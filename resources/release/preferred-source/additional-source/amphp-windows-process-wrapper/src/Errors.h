#pragma once

#define SUCCESS (0)
#define FAILURE (-1)

typedef int RESULT;

BOOL errors_init();
BOOL errors_destroy();
int errors_count();
BOOL error_push(LPCWSTR const format, ...);
BOOL system_error_push(const int code, LPCWSTR const format, ...);
void errors_output_all();
