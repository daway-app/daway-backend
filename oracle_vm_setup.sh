#!/bin/bash
# Setup MySQL 8 على آلة Oracle Cloud Always Free (Ubuntu)
# يشغّل مرة واحدة بحساب root (sudo). المتغيرات في بداية السكربت.

DB_NAME="daway_db"
DB_USER="daway_user"
DB_PASS="Daway@2026!Strong"

set -e

echo "[1/5] تحديث النظام..."
sudo apt-get update -y && sudo apt-get upgrade -y

echo "[2/5] تثبيت MySQL..."
sudo apt-get install -y mysql-server

echo "[3/5] تشغيل الخدمة وتمكينها..."
sudo systemctl enable mysql
sudo systemctl start mysql

echo "[4/5] إنشاء القاعدة والمستخدم (مع وصول عن بُعد)..."
sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
SQL

echo "[5/5] فتح الاتصال الشبكي..."
sudo sed -i 's/^bind-address.*/bind-address = 0.0.0.0/' /etc/mysql/mysql.conf.d/mysqld.cnf
sudo systemctl restart mysql

echo "DONE"
echo "DB     : $DB_NAME"
echo "USER   : $DB_USER"
echo "PASS   : $DB_PASS"
echo "PORT   : 3306"
echo ""
echo "ملاحظة: افتح المنفذ 3306 من Oracle Console:"
echo "VCN -> Security Lists -> ingress: TCP 3306 من 0.0.0.0/0"
echo "ثم أرسل لي IP الجهاز ومفتاح SSH وسأستورد البيانات من هنا."