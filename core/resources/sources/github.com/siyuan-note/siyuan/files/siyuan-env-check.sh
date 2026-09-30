#!/bin/sh
# In Docker the kernel exits at boot without a lock-screen password
# (kernel/model/conf.go), so fail the deploy with a message instead.
if [ -z "${SIYUAN_ACCESS_AUTH_CODE:-}" ] && [ "${SIYUAN_ACCESS_AUTH_CODE_BYPASS:-}" != "true" ]; then
    echo "siyuan: missing project environment variable SIYUAN_ACCESS_AUTH_CODE (the lock-screen password that protects the workspace). Set it, or set SIYUAN_ACCESS_AUTH_CODE_BYPASS=true to run without one, then redeploy." >&2
    exit 1
fi
echo "siyuan: access authentication configured"
