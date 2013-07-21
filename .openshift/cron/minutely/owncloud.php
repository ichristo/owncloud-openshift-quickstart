#!/bin/bash
# 
# Execute background job every 15 minutes

if [[ -f  $OPENSHIFT_PHP_REPO_DIR/php/cron.php ]] ; then 
    if [[ $(( $(date +%M) % 15 )) -eq 0 ]] ; then
        pushd $OPENSHIFT_PHP_REPO_DIR/php &>/dev/nul
        php -f cron.php
        popd &> /dev/null
    fi
fi
