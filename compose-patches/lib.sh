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
