#!/usr/bin/env bash
# Shared helpers sourced by compose patches.
#
# Two yq flavors exist with incompatible in-place flags:
#   Go yq   (mikefarah): yq -i 'expr' file
#   Python yq (kislyuk): yq -Yi 'expr' file  (-Y = YAML output, required with -i)

if yq --version 2>&1 | grep -qi 'mikefarah'; then
    _YQ_GO=1
else
    _YQ_GO=0
fi

yq_edit() {
    local expr="$1" file="$2"
    if [ "$_YQ_GO" -eq 1 ]; then
        yq -i "$expr" "$file"
    else
        yq -Yi "$expr" "$file"
    fi
}

# A fixed ULA subnet would collide the moment two installs (e.g. dev + prod on
# the same host, or two separate deployments) share a docker host, since
# docker refuses to create a network whose IPv6 pool overlaps another one.
random_ipv6_ula_subnet() {
    local b
    b=$(od -An -N5 -tx1 /dev/urandom | tr -d ' \n')
    printf 'fd%s:%s:%s::/64' "${b:0:2}" "${b:2:4}" "${b:6:4}"
}
