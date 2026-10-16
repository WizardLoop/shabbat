#pragma once

typedef union _in_addr_u {
    IN_ADDR *in_addr;
    IN6_ADDR *in6_addr;
} in_addr_t;

typedef struct _program_arguments {
    int server_port;
    in_addr_t server_address;
    int server_address_is_in6;
    int token_size;
    LPCWSTR exe_cwd;
} program_arguments_t;

BOOL parse_opts(program_arguments_t* program_arguments, const int argc, LPCWSTR* argv);
