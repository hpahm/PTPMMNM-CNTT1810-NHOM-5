#!/usr/bin/env bash
set -u
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)" || exit 2
cd "$root" || exit 2
case "${1:-help}" in
  check)
    command -v php >/dev/null || { echo "THIEU_PHP"; exit 2; }
    php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo "CAN_PHP_8_1_TRO_LEN"; exit 2; }
    driver="${OPENSHARE_DB_DRIVER:-sqlite}"
    php -r "exit(in_array('$driver', PDO::getAvailableDrivers(), true) ? 0 : 1);" || { echo "CAN_PDO_${driver^^}"; exit 2; }
    echo "MOI_TRUONG=SAN_SANG"
    ;;
  run)
    bash "$0" check || exit 2
    mkdir -p storage/files
    php -d session.save_path="$root/storage/sessions" -S 127.0.0.1:8127 -t public
    ;;
  test)
    bash "$0" check || exit 2
    php tests/run.php
    ;;
  reset)
    php -r "require 'src/bootstrap.php'; resetStore(); echo 'DU_LIEU_DA_KHOI_TAO\n';"
    ;;
  cleanup)
    php tools/cleanup.php "${2:---preview}"
    ;;
  help|-h|--help)
    echo "Cach dung: bash lab.sh check | run | test | reset"
    ;;
  *) echo "Lenh khong hop le"; exit 2 ;;
esac
