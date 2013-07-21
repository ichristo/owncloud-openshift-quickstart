#!/bin/bash
# 
# Execute background job every 15 minutes

if [[ -f  $OPENSHIFT_REPO_DIR/php/cron.php ]] ; then 
    if [[ $(( $(date +%M) % 15 )) -eq 0 ]] ; then
        printf "{\"app\":\"Cron\",\"message\":\"%s\",\"level\":2,\"time\":%s}\n" "Running cron job" $(date +%s) >> $OPENSHIFT_DATA_DIR/owncloud.log
        pushd $OPENSHIFT_REPO_DIR/php &> /dev/null
        php -f cron.php
        popd &> /dev/null
    fi
fi
