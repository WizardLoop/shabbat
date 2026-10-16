#pragma once

#define WSTR_TO_UTF8(wstr, utf8str, utf8strlen) WideCharToMultiByte(CP_UTF8, 0, wstr, -1, utf8str, utf8strlen, NULL, NULL)
#define WSTR_MEASURE_UTF8(wstr) WSTR_TO_UTF8(wstr, NULL, 0)

#define UTF8_TO_WSTR(utf8str, wstr, wstrlen) MultiByteToWideChar(CP_UTF8, 0, utf8str, -1, wstr, wstrlen)
#define UTF8_MEASURE_WSTR(utf8str) UTF8_TO_WSTR(utf8str, NULL, 0)
