#include <Windows.h>
#include <stdio.h>
#include "Errors.h"
#include "Encoding.h"

static PSLIST_HEADER errors;

typedef struct _error_item {
    SLIST_ENTRY entry;
    LPCWSTR message;
} error_item_t;

/*
* Polyfill for vaswprintf()
* See https://stackoverflow.com/a/40160038/889949
*/
static int vaswprintf(LPWSTR *strp, LPCWSTR const fmt, va_list ap)
{
    // _vscwprintf tells you how big the buffer needs to be
    const int len = _vscwprintf(fmt, ap);
    if (len == -1) {
        return -1;
    }

    const size_t size = (size_t)len + 1;
    LPWSTR str = malloc(size * sizeof(WCHAR));

    if (!str) {
        return -1;
    }

    // _vswprintf_s is the "secure" version of vswprintf
    const int r = vswprintf_s(str, len + 1, fmt, ap);

    if (r == -1) {
        free(str);
        return -1;
    }

    *strp = str;

    return r;
}

BOOL errors_init()
{
    if (errors != NULL) {
        return FALSE;
    }

    errors = _aligned_malloc(sizeof(SLIST_HEADER), MEMORY_ALLOCATION_ALIGNMENT);

    if (errors == NULL) {
        return FALSE;
    }

    InitializeSListHead(errors);

    return TRUE;
}

BOOL errors_destroy()
{
    if (errors == NULL) {
        return FALSE;
    }

    InterlockedFlushSList(errors);
    _aligned_free(errors);

    errors = NULL;

    return TRUE;
}

int errors_count()
{
    if (errors == NULL) {
        return -1;
    }

    return QueryDepthSList(errors);
}

BOOL error_push(LPCWSTR const format, ...)
{
    if (errors == NULL) {
        return FALSE;
    }

    error_item_t *item = _aligned_malloc(sizeof(error_item_t), MEMORY_ALLOCATION_ALIGNMENT);

    if (item == NULL) {
        return FALSE;
    }

    LPWSTR message;
    va_list ap;

    va_start(ap, format);
    const int length = vaswprintf(&message, format, ap);
    va_end(ap);

    /* trim whitespace from the end of the string */
    for (int i = length - 1; message[i] == L' ' || message[i] == L'\t' || message[i] == L'\r' || message[i] == L'\n'; i--) {
        message[i] = 0;
    }

    item->message = message;
    InterlockedPushEntrySList(errors, &item->entry);

    return TRUE;
}

BOOL system_error_push(const int code, LPCWSTR const format, ...)
{
    LPWSTR errstr = NULL;

    FormatMessageW(
        FORMAT_MESSAGE_ALLOCATE_BUFFER | FORMAT_MESSAGE_FROM_SYSTEM | FORMAT_MESSAGE_IGNORE_INSERTS,
        NULL, code,
        MAKELANGID(LANG_NEUTRAL, SUBLANG_DEFAULT),
        (LPWSTR)&errstr, 0, NULL
    );

    if (format == NULL) {
        return error_push(L"%d: %s", code, errstr);
    }

    va_list ap;
    LPWSTR extra;

    va_start(ap, format);
    vaswprintf(&extra, format, ap);
    va_end(ap);

    return error_push(L"%s: %d: %s", extra, code, errstr);
}

static LPWSTR error_pop()
{
    if (errors == NULL) {
        return NULL;
    }

    error_item_t *item = (error_item_t*)InterlockedPopEntrySList(errors);

    if (item == NULL) {
        return NULL;
    }

    LPCWSTR const message = item->message;
    _aligned_free(item);

    return (LPWSTR)message;
}

/*
 * Output error messages to stderr and return -1
 */
void errors_output_all()
{
    LPWSTR message;
    int buflen = 128;
    char *buffer = malloc(buflen);

    while (NULL != (message = error_pop())) {
        int result = WSTR_TO_UTF8(message, buffer, buflen);

        if (result == 0) {
            if (GetLastError() != ERROR_INSUFFICIENT_BUFFER) {
                continue;
            }

            result = WSTR_MEASURE_UTF8(message);
            if (result == 0) {
                continue;
            }

            char *tmp = realloc(buffer, result);
            if (tmp == NULL) {
                continue;
            }

            buffer = tmp;
            buflen = result;

            result = WSTR_TO_UTF8(message, buffer, buflen);
            if (result == 0) {
                continue;
            }
        }

        fprintf(stderr, "%s\n", buffer);
    }

    errors_destroy();
    free(buffer);
}
