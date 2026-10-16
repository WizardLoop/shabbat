#include <stdlib.h>
#include <string.h>
#include <WinSock2.h>
#include <WS2tcpip.h>
#include "Args.h"
#include "Errors.h"

typedef enum _arg_parse_result {
    ARG_PARSE_ERROR,
    ARG_PARSE_LAST_USE_CURRENT,
    ARG_PARSE_LAST_USE_NEXT,
    ARG_PARSE_NEED_VALUE,
    ARG_PARSE_SUCCESS,
} arg_parse_result_t;

static arg_parse_result_t parse_server_address_opt(program_arguments_t* program_arguments, LPCWSTR const opt_name, LPCWSTR const value);
static arg_parse_result_t parse_server_port_opt(program_arguments_t* program_arguments, LPCWSTR const opt_name, LPCWSTR const value);
static arg_parse_result_t parse_process_token_size_opt(program_arguments_t* program_arguments, LPCWSTR const opt_name, LPCWSTR const value);
static arg_parse_result_t parse_exe_cwd_opt(program_arguments_t* program_arguments, LPCWSTR const opt_name, LPCWSTR const value);

typedef arg_parse_result_t (arg_parser)(program_arguments_t* program_arguments, LPCWSTR opt_name);
typedef arg_parse_result_t (arg_value_parser)(program_arguments_t* program_arguments, LPCWSTR opt_name, LPCWSTR value);

typedef struct _arg_handler {
    LPCWSTR name;
    arg_parser *parse;
    arg_value_parser *parse_value;
} arg_handler_t;

#define ARG_HANDLER_COUNT 4
static arg_handler_t arg_handlers[ARG_HANDLER_COUNT] = {
    { .name = L"address", .parse_value = parse_server_address_opt },
    { .name = L"port", .parse_value = parse_server_port_opt },
    { .name = L"token-size", .parse_value = parse_process_token_size_opt },
    { .name = L"cwd", .parse_value = parse_exe_cwd_opt },
};

static arg_handler_t *get_arg_handler(LPCWSTR const opt_name)
{
    for (int i = 0; i < ARG_HANDLER_COUNT; i++) {
        if (wcscmp(opt_name, arg_handlers[i].name) == 0) {
            return &arg_handlers[i];
        }
    }

    return NULL;
}

static BOOL parse_int(LPCWSTR const str, int* result)
{
    LPWSTR end = NULL;

    errno = 0;
    const long number = wcstol(str, &end, 10);

    if (end == str || errno != 0) {
        return FALSE;
    }

    *result = (int)number;
    return TRUE;
}

static arg_parse_result_t parse_server_address_opt(program_arguments_t* program_arguments, LPCWSTR const opt_name, LPCWSTR const value)
{
    void *buf = malloc(sizeof(IN_ADDR));

    switch (InetPtonW(AF_INET, value, buf)) {
        case 0: /* Value is not a valid IPv4 address, try IPv6 */
            break;

        case 1: /* Value parsed successfully */
            program_arguments->server_address.in_addr = (IN_ADDR*)buf;
            program_arguments->server_address_is_in6 = 0;
            return ARG_PARSE_SUCCESS;

        default: /* Something else went wrong, there's an error in our logic somewhere */
            error_push(L"Error parsing server address");
            free(buf);
            return ARG_PARSE_ERROR;
    }

    free(buf);
    buf = malloc(sizeof(IN6_ADDR));

    switch (InetPtonW(AF_INET6, value, buf)) {
        case 0: /* Value is not a valid IPv6 address either */
            error_push(L"Invalid server address");
            free(buf);
            return ARG_PARSE_ERROR;

        case 1: /* Value parsed successfully */
            program_arguments->server_address.in6_addr = (IN6_ADDR*)buf;
            program_arguments->server_address_is_in6 = 1;
            return ARG_PARSE_SUCCESS;

        default: /* Something else went wrong, there's an error in our logic somewhere */
            error_push(L"Error parsing server address");
            free(buf);
            return ARG_PARSE_ERROR;
    }
}

static arg_parse_result_t parse_server_port_opt(program_arguments_t* program_arguments, LPCWSTR const opt_name, LPCWSTR const value)
{
    if (parse_int(value, &program_arguments->server_port)
        && program_arguments->server_port > 0 && program_arguments->server_port < 65536) {
        return ARG_PARSE_SUCCESS;
    }

    error_push(L"Invalid server port: %d", program_arguments->server_port);
    return ARG_PARSE_ERROR;
}

static arg_parse_result_t parse_process_token_size_opt(program_arguments_t* program_arguments, LPCWSTR const opt_name, LPCWSTR const value)
{
    if (parse_int(value, &program_arguments->token_size) && program_arguments->token_size > 0) {
        return ARG_PARSE_SUCCESS;
    }

    error_push(L"Invalid token size: %d", program_arguments->token_size);
    return ARG_PARSE_SUCCESS;
}

static arg_parse_result_t parse_exe_cwd_opt(program_arguments_t* program_arguments, LPCWSTR const opt_name, LPCWSTR const value)
{
    program_arguments->exe_cwd = value;
    return ARG_PARSE_SUCCESS;
}

static arg_parse_result_t parse_opt(program_arguments_t* program_arguments, LPCWSTR const opt, LPCWSTR *opt_name, const BOOL arg_value_only)
{
    arg_handler_t *handler;

    if (opt[0] == L'-' && opt[1] == L'-' && opt[2] == 0) {
        /* Argument is -- only */
        return ARG_PARSE_LAST_USE_NEXT;
    }

    if (arg_value_only) {
        /* Previous argument was a name only and requires a value */
        handler = get_arg_handler(*opt_name);
        return handler->parse_value(program_arguments, *opt_name, opt);
    }

    if (opt[0] != L'-' || opt[1] != L'-') {
        /* Argument does not start with -- */
        return ARG_PARSE_LAST_USE_CURRENT;
    }

    *opt_name = opt + 2;

    LPWSTR value = wcsstr(*opt_name, L"=");

    if (value == NULL) {
        /* Argument is a name only */
        handler = get_arg_handler(*opt_name);

        if (handler == NULL) {
            error_push(L"Unknown option: %s", *opt_name);
            return ARG_PARSE_ERROR;
        }
        
        if (handler->parse == NULL) {
            return ARG_PARSE_NEED_VALUE;
        }

        return handler->parse(program_arguments, *opt_name);
    }

    /* Argument has a value, null out the = to split into two strings */
    value[0] = 0;
    value++;

    handler = get_arg_handler(*opt_name);

    if (handler == NULL) {
        error_push(L"Unknown option: %s", *opt_name);
        return ARG_PARSE_ERROR;
    }

    if (handler->parse_value == NULL) {
        error_push(L"Option %s requires a value", *opt_name);
        return ARG_PARSE_ERROR;
    }

    return handler->parse_value(program_arguments, *opt_name, value);
}

static void init_program_arguments(program_arguments_t* program_arguments)
{
    program_arguments->server_port = 0;
    memset(&program_arguments->server_address, 0, sizeof(in_addr_t));
    program_arguments->server_address_is_in6 = -1;
    program_arguments->token_size = 0;
    program_arguments->exe_cwd = NULL;
}

static BOOL validate_program_arguments(program_arguments_t* program_arguments)
{
    if (program_arguments->token_size == 0) {
        error_push(L"Process label not supplied");
        return FALSE;
    }

    if (program_arguments->server_port == 0) {
        error_push(L"Server port not supplied");
        return FALSE;
    }

    return TRUE;
}

BOOL parse_opts(program_arguments_t* program_arguments, const int argc, LPCWSTR* argv)
{
    BOOL arg_value_only = FALSE;
    LPCWSTR opt_name = NULL;

    init_program_arguments(program_arguments);

    for (int i = 1; i < argc; i++) {
        switch (parse_opt(program_arguments, argv[i], &opt_name, arg_value_only)) {
        case ARG_PARSE_ERROR:
            return FALSE;

        case ARG_PARSE_LAST_USE_CURRENT:
        case ARG_PARSE_LAST_USE_NEXT:
            goto end_parse_opts;

        case ARG_PARSE_NEED_VALUE:
            arg_value_only = TRUE;
            break;

        case ARG_PARSE_SUCCESS:
            arg_value_only = FALSE;
            break;
        }
    }

end_parse_opts:
    if (program_arguments->server_address_is_in6 == -1) {
        parse_server_address_opt(program_arguments, L"address", L"127.0.0.1");
    }

    return validate_program_arguments(program_arguments);
}
