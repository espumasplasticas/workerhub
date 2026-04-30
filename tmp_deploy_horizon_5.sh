set -e
cd /home/tolomeo/workerhub
git pull origin dev
docker compose exec -T php-1 php artisan optimize:clear
docker compose restart horizon
sleep 8
docker compose exec -T php-1 php artisan tinker --execute='dump(["app_env" => config("app.env"), "receipts" => config("horizon.environments.local.supervisor-receipts"), "orders" => config("horizon.environments.local.supervisor-sales-orders"), "invoices" => config("horizon.environments.local.supervisor-invoices"), "integrations" => config("horizon.environments.local.supervisor-integrations")]);'
