#!/usr/bin/env bash

SCRIPTPATH="$( cd "$(dirname "$0")" ; pwd -P )"
MATOMO_ROOT="$SCRIPTPATH/../app/";

php MATOMO_ROOT/console wordpress:generate-language-files

# we need to remove jquery as it is shipped with wordpress and we use their jquery
rm -rf $MATOMO_ROOT/libs/bower_components/jquery
rm -rf $MATOMO_ROOT/libs/bower_components/jquery-ui

# lets remove some extra libs that aren't needed
find $MATOMO_ROOT/libs/bower_components -name '*.js' -exec rm -rf {} +
find $MATOMO_ROOT/libs/bower_components -name '*.map' -exec rm -rf {} +
find $MATOMO_ROOT/libs/jqplot -name '*.js' -exec rm -rf {} +
find $MATOMO_ROOT/plugins/*/angularjs -name '*.js' -exec rm -rf {} +
find $MATOMO_ROOT/plugins/*/javascripts -name '*.js' ! -name 'Piwik_Overlay.js' -exec rm -rf {} +
find $MATOMO_ROOT/plugins/*/lang -name '*.json' -exec rm -rf {} +

echo -e "Done!... "
