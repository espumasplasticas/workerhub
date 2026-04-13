#!/bin/sh
set -eu

cat > /app/laravel-echo-server.json <<EOF
{
    "authHost": "${ECHO_AUTH_HOST:-http://nginx}",
    "authEndpoint": "${ECHO_AUTH_ENDPOINT:-/broadcasting/auth}",
    "clients": [
        {
            "appId": "${PUSHER_APP_ID:-workerhub}",
            "key": "${PUSHER_APP_KEY:-workerhub-key}"
        }
    ],
    "database": "redis",
    "databaseConfig": {
        "redis": {
            "host": "${REDIS_HOST:-redis}",
            "port": "${REDIS_PORT:-6379}"
        },
        "sqlite": {
            "databasePath": "/database/laravel-echo-server.sqlite"
        }
    },
    "devMode": true,
    "host": null,
    "port": "${ECHO_SERVER_PORT:-6001}",
    "protocol": "${ECHO_SERVER_SCHEME:-http}",
    "socketio": {},
    "secureOptions": 67108864,
    "sslCertPath": "",
    "sslKeyPath": "",
    "sslCertChainPath": "",
    "sslPassphrase": "",
    "subscribers": {
        "http": true,
        "redis": true
    },
    "apiOriginAllow": {
        "allowCors": true,
        "allowOrigin": "*",
        "allowMethods": "*",
        "allowHeaders": "*"
    }
}
EOF

exec laravel-echo-server start
