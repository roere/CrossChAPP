#!/bin/sh
set -eu

run_race() {
    task=$1; expected=$2; processes=$3; pids=''; successes=0; i=1
    while [ "$i" -le "$processes" ]; do
        php /var/www/tests/mysql-race-worker.php "$task" &
        pids="$pids $!"; i=$((i + 1))
    done
    for pid in $pids; do
        if wait "$pid"; then successes=$((successes + 1)); fi
    done
    if [ "$successes" -ne "$expected" ]; then
        echo "FAIL $task: erwartet $expected Gewinner, erhalten $successes" >&2; exit 1
    fi
    echo "PASS $task: $successes atomarer Gewinner"
}

php -r 'require "/var/www/html/src/Database.php";$d=(new Database())->connection();$d->exec("DELETE FROM chapter_refresh_log");$d->exec("DELETE FROM chapter_refresh_locks WHERE org_id=910001");$d->exec("DELETE FROM representation_offers WHERE user_id=(SELECT id FROM users WHERE email=\"check-c@example.test\") AND org_id=910001");$d->exec("DELETE FROM user_invitations WHERE email=\"mysql-race@example.test\"");$h=hash("sha256","mysql-race-login@example.test");$d->prepare("DELETE FROM auth_attempts WHERE identifier_hash=?")->execute([$h]);'
run_race login-attempt 12 12
run_race daily-limit 3 12
run_race worker-lock 1 12
run_race offer 1 12
run_race invitation 1 12

php -r 'require "/var/www/html/src/Database.php";$d=(new Database())->connection();$loginHash=hash("sha256","mysql-race-login@example.test");$checks=["login"=>["SELECT COUNT(*) FROM auth_attempts WHERE identifier_hash=".$d->quote($loginHash),12],"daily"=>["SELECT COUNT(*) FROM chapter_refresh_log WHERE trigger_type=\"automatic\"",3],"lock"=>["SELECT COUNT(*) FROM chapter_refresh_locks WHERE org_id=910001",1],"offer"=>["SELECT COUNT(*) FROM representation_offers WHERE user_id=(SELECT id FROM users WHERE email=\"check-c@example.test\") AND org_id=910001",1],"invitation"=>["SELECT COUNT(*) FROM user_invitations WHERE email=\"mysql-race@example.test\" AND status=\"pending\"",1]];foreach($checks as$n=>[$sql,$expected]){$count=(int)$d->query($sql)->fetchColumn();if($count!==$expected)throw new RuntimeException("$n count=$count");}echo "PASS MariaDB Race-Counts\n";'
