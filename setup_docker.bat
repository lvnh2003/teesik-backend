@echo off
echo Đang cài đặt các dependencies qua Composer (bên trong Docker)...
docker exec -it teesik-app composer install

echo.
echo Đang chạy cấu hình môi trường...
docker exec -it teesik-app php artisan key:generate

echo.
echo Đang chạy migrations...
docker exec -it teesik-app php artisan migrate --force

echo.
echo Đang cài đặt Passport (tạo encryption keys & clients)...
docker exec -it teesik-app php artisan passport:install --force

echo.
echo Hoàn tất thiết lập Backend! Bây giờ bạn có thể thử trải nghiệm web ở http://localhost:3000
pause
