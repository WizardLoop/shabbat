#include <stdio.h>
#include <WS2tcpip.h>
#include "Errors.h"
#include "Args.h"
#include "Encoding.h"

#pragma comment(lib, "Ws2_32.lib")

#define SOCKET_COUNT 3
#define BUFFER_SIZE 1024

#define TOKEN_SIZE globals.arguments.token_size
#define SERVER_TOKEN(id) (globals.server_tokens.buf + ((id) * TOKEN_SIZE))
#define CLIENT_TOKEN(id) (globals.client_tokens.buf + ((id) * TOKEN_SIZE))

typedef enum _socket_state {
    SOCKET_STATE_WAIT_CONNECT,
    SOCKET_STATE_WAIT_ACK,
    SOCKET_STATE_CONNECTED,
    SOCKET_STATE_ERROR,
} socket_state_t;

typedef struct _socket_info {
    int id;
    SOCKET socket;
    socket_state_t state;
} socket_info_t;

#define HANDSHAKE_STATUS_OPTION_COUNT 7

enum handshake_status {
    HANDSHAKE_STATUS_SUCCESS,
    HANDSHAKE_STATUS_SIGNAL_UNEXPECTED,
    HANDSHAKE_STATUS_INVALID_STREAM_ID,
    HANDSHAKE_STATUS_INVALID_PROCESS_ID,
    HANDSHAKE_STATUS_DUPLICATE_STREAM_ID,
    HANDSHAKE_STATUS_INVALID_CLIENT_TOKEN,
    HANDSHAKE_STATUS_INVALID_SERVER_TOKEN,
};

LPCWSTR handshake_status_messages[HANDSHAKE_STATUS_OPTION_COUNT] = {
    L"Success",
    L"Signal not expected at this time",
    L"Invalid stream identifier",
    L"Invalid process identifier",
    L"Duplicate stream identifier",
    L"Invalid client security token",
    L"Invalid server security token",
};

typedef enum _signal_code {
    SIGNAL_CODE_HANDSHAKE = 0x01,
    SIGNAL_CODE_HANDSHAKE_ACK = 0x02,
    SIGNAL_CODE_CHILD_PID = 0x03,
    SIGNAL_CODE_EXIT_CODE = 0x04,
} signal_code_t;

typedef struct _pipe {
    HANDLE read;
    HANDLE write;
} pipe_t;

typedef struct _process_info {
    pipe_t pipes[3];
    PROCESS_INFORMATION process_info;
    SECURITY_ATTRIBUTES security_attributes;
    STARTUPINFOW start_info;
} process_info_t;

typedef struct _file_socket_pair {
    int id;
    HANDLE file;
    SOCKET socket;
} file_socket_pair_t;

static struct {
    program_arguments_t arguments;
    WSABUF client_tokens;
    WSABUF server_tokens;
    LPWSTR exe_command_line;
} globals;

WSADATA wsa_data;

/*
 * Encode a DWORD to a char buffer in network byte order
 */
static void dword_to_buffer(const DWORD value, char* buffer)
{
    buffer[0] = value >> 24 & 0xFF;
    buffer[1] = value >> 16 & 0xFF;
    buffer[2] = value >> 8 & 0xFF;
    buffer[3] = value & 0xFF;
}

static BOOL socket_connect(socket_info_t* socket_info)
{
    SOCKADDR* sockaddr;
    int namelen;
    u_long non_blocking = 1;

    int result = ioctlsocket(socket_info->socket, FIONBIO, &non_blocking);

    if (result != NO_ERROR) {
        error_push(L"Failed to set socket #%d to non-blocking mode, failed with %d", socket_info->id, result);
        return FALSE;
    }

    if (globals.arguments.server_address_is_in6) {
        struct sockaddr_in6 addr;
        namelen = sizeof(addr);
        addr.sin6_family = AF_INET6;
        addr.sin6_addr = *globals.arguments.server_address.in6_addr;
        addr.sin6_flowinfo = 0; /* I have no idea what this is for, but it doesn't seem to matter */
        addr.sin6_port = htons(globals.arguments.server_port);
        sockaddr = (SOCKADDR*)&addr;
    } else {
        struct sockaddr_in addr;
        namelen = sizeof(addr);
        addr.sin_family = AF_INET;
        addr.sin_addr = *globals.arguments.server_address.in_addr;
        addr.sin_port = htons(globals.arguments.server_port);
        sockaddr = (SOCKADDR*)&addr;
    }

    result = WSAConnect(socket_info->socket, sockaddr, namelen, NULL, NULL, NULL, NULL);

    /* Should fail with WSAEWOULDBLOCK */
    if (result != SOCKET_ERROR) {
        error_push(L"Connecting socket #%d unexpectedly succeeded", socket_info->id);
        return FALSE;
    }

    const int error = WSAGetLastError();

    if (error != WSAEWOULDBLOCK) {
        system_error_push(error, L"Connecting socket #%d failed", socket_info->id);
        return FALSE;
    }

    return TRUE;
}

/*
 * Send data to a socket
 *
 * This function returns TRUE only if all the data in the supplied buffers was successfully sent in a single operation. In practice this should be
 * a safe assumption, since the sockets are in blocking mode while passing through child process data, and all other data exchange is done in very
 * small chunks.
 */
static BOOL socket_send(const SOCKET socket, const int id, WSABUF *buffers, const DWORD buffer_count, LPCWSTR const description)
{
    ULONG length = 0;
    DWORD bytes_written;

    /* Assume that the socket is writable without blocking, at this point the internal buffer must be empty */
    const int result = WSASend(socket, buffers, buffer_count, &bytes_written, 0, NULL, NULL);

    if (result == SOCKET_ERROR) {
        system_error_push(WSAGetLastError(), L"Failed to send %s to socket #%d", description, id);
        return FALSE;
    }

    for (DWORD i = 0; i < buffer_count; i++) {
        length += buffers[i].len;
    }

    if (bytes_written != length) {
        error_push(L"Failed to send %s to socket #%d: sent %d of %d bytes", description, id, bytes_written, length);
        return FALSE;
    }

    return TRUE;
}

/*
 * Process writability on a socket which is connecting
 */
static BOOL socket_process_connect_writable(socket_info_t* socket_info)
{
    u_long non_blocking = 0;

    const int result = ioctlsocket(socket_info->socket, FIONBIO, &non_blocking);

    if (result != NO_ERROR) {
        error_push(L"Failed to set socket #%d to blocking mode, failed with %d", socket_info->id, result);
        return FALSE;
    }

    char socket_id[6];
    const WSABUF socket_id_buffer = { .len = 6,.buf = socket_id };
    const WSABUF token_buffer = { .len = TOKEN_SIZE, .buf = CLIENT_TOKEN(socket_info->id) };
    WSABUF buffers[2];

    socket_id[0] = SIGNAL_CODE_HANDSHAKE;
    dword_to_buffer(GetCurrentProcessId(), socket_id + 1);
    socket_id[5] = socket_info->id;

    buffers[0] = socket_id_buffer;
    buffers[1] = token_buffer;

    if (!socket_send(socket_info->socket, socket_info->id, buffers, 2, L"handshake")) {
        return FALSE;
    }

    socket_info->state = SOCKET_STATE_WAIT_ACK;

    return TRUE;
}

/*
 * Process readability on a socket which is connecting
 */
static BOOL socket_process_connect_readable(socket_info_t* socket_info)
{
    WSABUF buffer;
    DWORD bytes_read, flags = 0;

    buffer.len = TOKEN_SIZE + 2;
    buffer.buf = malloc(buffer.len);

    const int result = WSARecv(socket_info->socket, &buffer, 1, &bytes_read, &flags, NULL, NULL);

    if (result == SOCKET_ERROR) {
        system_error_push(WSAGetLastError(), L"Failed to read handshake data from socket #%d", socket_info->id);
        free(buffer.buf);
        return FALSE;
    }

    if (bytes_read < 2) {
        error_push(L"Failed to read handshake data from socket #%d: received %d of expected %d bytes", socket_info->id, bytes_read, buffer.len);
        free(buffer.buf);
        return FALSE;
    }

    if (buffer.buf[0] != SIGNAL_CODE_HANDSHAKE_ACK) {
        error_push(L"Handshake failed for socket #%d: Unexpected signal code %d from server, expecting HANDSHAKE_ACK", socket_info->id, buffer.buf[0]);
        free(buffer.buf);
        return FALSE;
    }

    if (buffer.buf[1] != HANDSHAKE_STATUS_SUCCESS) {
        error_push(
            L"Handshake failed for socket #%d: Server rejected connection: %d: %s", 
            socket_info->id, buffer.buf[1], 
            buffer.buf[1] < HANDSHAKE_STATUS_OPTION_COUNT ? handshake_status_messages[(unsigned char)buffer.buf[1]] : L"Unknown error"
        );
        free(buffer.buf);
        return FALSE;
    }

    /* If we get this far then we need to ACK the server's message */
    char ack_message[2] = { SIGNAL_CODE_HANDSHAKE_ACK, HANDSHAKE_STATUS_INVALID_SERVER_TOKEN };
    WSABUF ack_buffer = { .len = 2, .buf = ack_message };
    BOOL success = FALSE;

    if (bytes_read != buffer.len) {
        error_push(L"Failed to read handshake data from socket #%d: received %d of expected %d bytes", socket_info->id, bytes_read, buffer.len);
    } else if (memcmp(buffer.buf + 2, SERVER_TOKEN(socket_info->id), TOKEN_SIZE) != 0) {
        error_push(L"Handshake failed for socket #%d: Invalid server token", socket_info->id);
    } else {
        ack_message[1] = HANDSHAKE_STATUS_SUCCESS;
        socket_info->state = SOCKET_STATE_CONNECTED;
        success = TRUE;
    }

    free(buffer.buf);

    if (!socket_send(socket_info->socket, socket_info->id, &ack_buffer, 1, L"handshake ack")) {
        return FALSE;
    }

    return success;
}

/*
 * Process error on a socket which is connecting
 */
static void socket_process_connect_error(socket_info_t* socket_info)
{
    int code;
    int len = sizeof(int);

    const int result = getsockopt(socket_info->socket, SOL_SOCKET, SO_ERROR, (char*)&code, &len);

    if (result == SOCKET_ERROR) {
        error_push(L"Failed to connect socket #%d: Unknown error", socket_info->id);
    } else {
        system_error_push(code, L"Failed to connect socket #%d", socket_info->id);
    }
}

/*
 * Determines whether any sockets have pending connect actions
 */
static BOOL socketset_have_pending_connect(socket_info_t **sockets)
{
    for (int i = 0; i < SOCKET_COUNT; i++) {
        if (sockets[i]->state < SOCKET_STATE_CONNECTED) {
            return TRUE;
        }
    }

    return FALSE;
}

/*
 * Initialize the sockets array
 */
static BOOL socketset_create(socket_info_t** sockets, int count)
{
    const int address_family = globals.arguments.server_address_is_in6 ? AF_INET6 : AF_INET;

    for (int i = 0; i < count; i++) {
        socket_info_t* socket_info = malloc(sizeof(socket_info_t));

        socket_info->id = i;
        socket_info->state = SOCKET_STATE_WAIT_CONNECT;
        socket_info->socket = socket(address_family, SOCK_STREAM, IPPROTO_TCP);

        if (socket_info->socket == INVALID_SOCKET) {
            error_push(L"Failed to create socket #%d", socket_info->id);
            return FALSE;
        }

        sockets[i] = socket_info;
    }

    return TRUE;
}

/*
 * Thread main routine to connect sockets
 */
static DWORD WINAPI socketset_connect(LPVOID param)
{
    socket_info_t **sockets = (socket_info_t**)param;
    FD_SET read_set;
    FD_SET write_set;
    FD_SET error_set;

    for (int i = 0; i < SOCKET_COUNT; i++) {
        if (!socket_connect(sockets[i])) {
            return FAILURE;
        }
    }

    while (socketset_have_pending_connect(sockets)) {
        FD_ZERO(&read_set);
        FD_ZERO(&write_set);
        FD_ZERO(&error_set);

        for (int i = 0; i < SOCKET_COUNT; i++) {
            if (sockets[i]->state == SOCKET_STATE_WAIT_CONNECT) {
                FD_SET(sockets[i]->socket, &write_set);
                FD_SET(sockets[i]->socket, &error_set);
            } else if (sockets[i]->state == SOCKET_STATE_WAIT_ACK) {
                FD_SET(sockets[i]->socket, &read_set);
            }
        }

        int pending_activity_count = select(0, &read_set, &write_set, &error_set, NULL);

        if (pending_activity_count == SOCKET_ERROR) {
            system_error_push(WSAGetLastError(), L"select() operation failed");
            return FAILURE;
        }

        if (pending_activity_count == 0) {
            error_push(L"select() unexpectedly returned zero sockets");
            return FAILURE;
        }

        for (int i = 0; pending_activity_count > 0 && i < SOCKET_COUNT; i++) {
            if (FD_ISSET(sockets[i]->socket, &read_set)) {
                if (!socket_process_connect_readable(sockets[i])) {
                    return FAILURE;
                }

                pending_activity_count--;
            } else if (FD_ISSET(sockets[i]->socket, &write_set)) {
                if (!socket_process_connect_writable(sockets[i])) {
                    return FAILURE;
                }

                pending_activity_count--;
            } else if (FD_ISSET(sockets[i]->socket, &error_set)) {
                socket_process_connect_error(sockets[i]);
                return FAILURE;
            }
        }
    }

    return SUCCESS;
}

static BOOL process_init(process_info_t *process_info)
{
    process_info->security_attributes.nLength = sizeof(SECURITY_ATTRIBUTES);
    process_info->security_attributes.bInheritHandle = TRUE;
    process_info->security_attributes.lpSecurityDescriptor = NULL;

    for (int i = 0; i < 3; i++) {
        if (!CreatePipe(&process_info->pipes[i].read, &process_info->pipes[i].write, &process_info->security_attributes, 0)) {
            error_push(L"Failed to create pipe for process I/O stream #%d", i);
            return FALSE;
        }

        if (!SetHandleInformation(i == 0 ? process_info->pipes[i].write : process_info->pipes[i].read, HANDLE_FLAG_INHERIT, 0)) {
            error_push(L"Failed to set handle information for process I/O stream #%d", i);
            return FALSE;
        }
    }

    process_info->start_info.cb = sizeof(STARTUPINFO);
    process_info->start_info.hStdInput = process_info->pipes[0].read;
    process_info->start_info.hStdOutput = process_info->pipes[1].write;
    process_info->start_info.hStdError = process_info->pipes[2].write;
    process_info->start_info.dwFlags |= STARTF_USESTDHANDLES;

    return TRUE;
}

static BOOL process_start(process_info_t *process_info)
{
    const BOOL result = CreateProcessW(
        NULL,
        globals.exe_command_line,
        &process_info->security_attributes, // process security attributes 
        &process_info->security_attributes, // primary thread security attributes 
        TRUE, // handles are inherited 
        CREATE_NO_WINDOW | NORMAL_PRIORITY_CLASS,
        NULL, // use parent's environment 
        globals.arguments.exe_cwd,
        &process_info->start_info,
        &process_info->process_info
    );

    if (!result) {
        system_error_push(GetLastError(), L"Failed to create child process");
        return FALSE;
    }

    CloseHandle(process_info->process_info.hThread);
    CloseHandle(process_info->pipes[0].read);
    CloseHandle(process_info->pipes[1].write);
    CloseHandle(process_info->pipes[2].write);

    return TRUE;
}

static DWORD WINAPI copy_output_to_socket(LPVOID param)
{
    DWORD bytes_read;
    WSABUF buffer;
    int result = FAILURE;
    file_socket_pair_t *pair = (file_socket_pair_t*)param;

    buffer.buf = malloc(BUFFER_SIZE);
    buffer.len = BUFFER_SIZE;

    while (TRUE) {
        if (!ReadFile(pair->file, buffer.buf, BUFFER_SIZE, &bytes_read, NULL)) {
            const int error = GetLastError();

            /* The child process ended (may also succeed with zero bytes depending on the order in which things happen) */
            if (error == ERROR_BROKEN_PIPE) {
                goto success;
            }

            system_error_push(GetLastError(), L"Failed to read from child process pipe #%d", pair->id);
            goto failure;
        }

        /* The child process ended */
        if (bytes_read == 0) {
            goto success;
        }

        buffer.len = bytes_read;

        if (!socket_send(pair->socket, pair->id, &buffer, 1, L"data")) {
            goto failure;
        }
    }

success:
    result = SUCCESS;

failure:
    CloseHandle(pair->file);
    closesocket(pair->socket);

    return result;
}

static DWORD WINAPI copy_socket_to_input(LPVOID param)
{
    DWORD bytes_read, bytes_written, flags = 0;
    WSABUF buffer;
    int result = FAILURE;
    file_socket_pair_t *pair = (file_socket_pair_t*)param;

    buffer.buf = malloc(BUFFER_SIZE);
    buffer.len = BUFFER_SIZE;

    while (TRUE) {
        if (WSARecv(pair->socket, &buffer, 1, &bytes_read, &flags, NULL, NULL) == SOCKET_ERROR) {
            system_error_push(GetLastError(), L"Failed to read from socket #%d", pair->id);
            goto failure;
        }

        if (bytes_read == 0) {
            goto success;
        }

        if (!WriteFile(pair->file, buffer.buf, bytes_read, &bytes_written, NULL)) {
            system_error_push(GetLastError(), L"Failed to send data to child process pipe #%d", pair->id);
            goto failure;
        }

        if (bytes_written != bytes_read) {
            error_push(L"Failed to send data to child process pipe #%d: sent %d of %d bytes", pair->id, bytes_written, bytes_read);
            goto failure;
        }
    }

success:
    result = SUCCESS;

failure:
    /* Don't close the socket, we'll use it to inform the parent when the process is finished */
    CloseHandle(pair->file);

    return result;
}

BOOL get_tokens_from_stdin()
{
    DWORD bytes_read;
    const DWORD expected_token_size = TOKEN_SIZE * 6;
    const ULONG peer_tokens_len = TOKEN_SIZE * 3;
    HANDLE stdin_handle = GetStdHandle(STD_INPUT_HANDLE);
    char buffer[65536]; /* https://stackoverflow.com/a/28452546 */

    if (!ReadFile(stdin_handle, buffer, expected_token_size, &bytes_read, NULL)) {
        system_error_push(GetLastError(), L"Failed to read tokens from stdin");
        return FALSE;
    }

    if (bytes_read != expected_token_size) {
        error_push(L"Failed to read tokens from stdin: received %d of expected %d bytes", bytes_read, expected_token_size);
        return FALSE;
    }

    globals.client_tokens.len = peer_tokens_len;
    globals.client_tokens.buf = malloc(peer_tokens_len);
    memcpy(globals.client_tokens.buf, buffer, peer_tokens_len);

    globals.server_tokens.len = peer_tokens_len;
    globals.server_tokens.buf = malloc(peer_tokens_len);
    memcpy(globals.server_tokens.buf, buffer + peer_tokens_len, peer_tokens_len);

    if (!ReadFile(stdin_handle, buffer, 1, &bytes_read, NULL)) {
        system_error_push(GetLastError(), L"Failed to read token-command separator from stdin");
        return FALSE;
    }

    if (bytes_read != 1) {
        error_push(L"Failed to read token-command separator from stdin: received %d of expected 1 byte", bytes_read);
        return FALSE;
    }

    if (buffer[0] != 0) {
        error_push(L"Failed to read token-command separator from stdin: expected 0, got %d", buffer[0]);
        return FALSE;
    }

    if (!ReadFile(stdin_handle, buffer, 65536, &bytes_read, NULL)) {
        system_error_push(GetLastError(), L"Failed to read command from stdin");
        return FALSE;
    }

    if (bytes_read == 65536) {
        error_push(L"Failed to read command from stdin: command too long");
        return FALSE;
    }

    if (buffer[bytes_read - 1] != 0) {
        error_push(L"Failed to read command from stdin: missing null terminator");
        return FALSE;
    }

    const int len = UTF8_MEASURE_WSTR(buffer);

    if (len == 0) {
        error_push(L"Failed to read command from stdin: invalid UTF-8 string");
        return FALSE;
    }

    globals.exe_command_line = malloc(len * sizeof(WCHAR));

    if (UTF8_TO_WSTR(buffer, globals.exe_command_line, len) == 0) {
        error_push(L"Failed to read command from stdin: failed to decode UTF-8 string");
        return FALSE;
    }

    return TRUE;
}

static BOOL send_dword_to_parent(socket_info_t *socket, const signal_code_t signal_code, const DWORD data, LPCWSTR const description)
{
    char bytes[5] = { signal_code };
    WSABUF buffer = { .len = 5, .buf = bytes };

    dword_to_buffer(data, bytes + 1);

    return socket_send(socket->socket, socket->id, &buffer, 1, description);
}

static BOOL wait_for_threads(HANDLE *copy_threads)
{
    /* Check if any stream copy threads are still active and wait for them if they are */
    HANDLE active_threads[SOCKET_COUNT];
    int active_thread_count = 0;
    DWORD code;

    for (int i = 0; i < SOCKET_COUNT; i++) {
        if (!GetExitCodeThread(copy_threads[i], &code)) {
            system_error_push(GetLastError(), L"Retrieving copy thread #%d exit code failed", i);
            return FALSE;
        }

        if (code == STILL_ACTIVE) {
            active_threads[active_thread_count++] = copy_threads[i];
        } else if (code != SUCCESS) {
            return FALSE;
        }
    }

    if (active_thread_count == 0) {
        return TRUE;
    }

    if (WaitForMultipleObjects(active_thread_count, active_threads, TRUE, INFINITE) == WAIT_FAILED) {
        system_error_push(GetLastError(), L"Wait operation on copy threads failed");
    }

    for (int i = 0; i < SOCKET_COUNT; i++) {
        if (!GetExitCodeThread(copy_threads[i], &code)) {
            system_error_push(GetLastError(), L"Retrieving copy thread #%d exit code failed", i);
            return FALSE;
        }

        if (code != SUCCESS) {
            return FALSE;
        }
    }

    return TRUE;
}

int wmain(const int argc, LPCWSTR* argv)
{
    DWORD exit_code;
    socket_info_t* sockets[SOCKET_COUNT];

    /* Initialize error handlers */
    errors_init();

    /* Initialize Winsock */
    const int result = WSAStartup(MAKEWORD(2, 2), &wsa_data);

    if (result != 0) {
        error_push(L"WSAStartup failed: %d", result);
    }

    /* Parse args */
    if (!parse_opts(&globals.arguments, argc, argv)) {
        errors_output_all();
        return FAILURE;
    }

    /* Get process identifier token from stdin */
    if (!get_tokens_from_stdin()) {
        errors_output_all();
        return FAILURE;
    }

    /* Connect to server */
    if (!socketset_create(sockets, SOCKET_COUNT)) {
        errors_output_all();
        return FAILURE;
    }

    HANDLE connect_thread = CreateThread(NULL, 0, socketset_connect, &sockets, 0, NULL);

    /* Initialize the data for the child process */
    process_info_t process_info;
    ZeroMemory(&process_info, sizeof(process_info_t));

    if (!process_init(&process_info)) {
        errors_output_all();
        return FAILURE;
    }

    /* Wait until server is connected */
    if (WaitForSingleObject(connect_thread, INFINITE) == WAIT_FAILED) {
        system_error_push(GetLastError(), L"Wait operation on connect thread failed");
        errors_output_all();
        return FAILURE;
    }
        
    if (!GetExitCodeThread(connect_thread, &exit_code)) {
        system_error_push(GetLastError(), L"Retrieving connect thread exit code failed");
        errors_output_all();
        return FAILURE;
    }

    if (exit_code != SUCCESS) {
        errors_output_all();
        return FAILURE;
    }

    /* Start the process */
    if (!process_start(&process_info)) {
        errors_output_all();
        return FAILURE;
    }

    /* Send the process id to the parent on the stdin socket - despite the fact that another
    * thread might be doing stuff with this socket it is safe because it will only be reading */
    if (!send_dword_to_parent(sockets[0], SIGNAL_CODE_CHILD_PID, process_info.process_info.dwProcessId, L"PID")) {
        errors_output_all();
        return FAILURE;
    }
    
    /* Start stream copy threads */
    HANDLE copy_threads[SOCKET_COUNT];

    file_socket_pair_t stdin_pair;
    stdin_pair.id = 0;
    stdin_pair.socket = sockets[0]->socket;
    stdin_pair.file = process_info.pipes[0].write;
    copy_threads[0] = CreateThread(NULL, 0, copy_socket_to_input, &stdin_pair, 0, NULL);

    file_socket_pair_t stdout_pair;
    stdout_pair.id = 1;
    stdout_pair.socket = sockets[1]->socket;
    stdout_pair.file = process_info.pipes[1].read;
    copy_threads[1] = CreateThread(NULL, 0, copy_output_to_socket, &stdout_pair, 0, NULL);

    file_socket_pair_t stderr_pair;
    stderr_pair.id = 2;
    stderr_pair.socket = sockets[2]->socket;
    stderr_pair.file = process_info.pipes[2].read;
    copy_threads[2] = CreateThread(NULL, 0, copy_output_to_socket, &stderr_pair, 0, NULL);

    /* Wait until the child process ends */
    if (WaitForSingleObject(process_info.process_info.hProcess, INFINITE) == WAIT_FAILED) {
        system_error_push(GetLastError(), L"Wait operation on child process failed");
        errors_output_all();
        return FAILURE;
    }

    /* Get the process' exit code */
    if (!GetExitCodeProcess(process_info.process_info.hProcess, &exit_code)) {
        system_error_push(GetLastError(), L"Retrieving process exit code failed");
        errors_output_all();
        return FAILURE;
    }

    /* Send the process exit code to the parent on the stdin socket */
    if (!send_dword_to_parent(sockets[0], SIGNAL_CODE_EXIT_CODE, exit_code, L"exit code")) {
        errors_output_all();
        return FAILURE;
    }

    /* Check if any stream copy threads are still active and wait for them if they are */
    if (!wait_for_threads(copy_threads)) {
        errors_output_all();
        return FAILURE;
    }

    /* Don't close the stdin socket until all data has been sent *and* the copy thread as ended */
    closesocket(sockets[0]->socket);

    /* Exit with the same code as the child */
    return exit_code;
}
