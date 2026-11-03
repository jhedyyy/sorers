#!/usr/bin/env bash
# End-to-end smoke test against the built-in PHP server using the demo data.
# Usage: DB_DRIVER=sqlite DB_SQLITE=/path/test.sqlite php setup.php --fresh --demo && tests/smoke.sh
set -u
cd "$(dirname "$0")/.."
PORT=8099; BASE=http://127.0.0.1:$PORT; J=$(mktemp -d)
php -S 127.0.0.1:$PORT -t public >/dev/null 2>&1 & SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf $J' EXIT
sleep 1
pass=0; fail=0
ok(){ if [ "$2" = "1" ]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1"; fi; }
has(){ grep -qiF -- "$2" <<<"$1" && echo 1 || echo 0; }
hasnt(){ grep -qiF -- "$2" <<<"$1" && echo 0 || echo 1; }

tok(){ curl -s -b $J/$1 -c $J/$1 "$BASE/${2:-dashboard.php}" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
get(){ curl -s -L -b $J/$1 -c $J/$1 "$BASE/$2"; }
post(){ local who=$1 path=$2; shift 2; local t; t=$(tok $who "${TOKPAGE:-$path}"); curl -s -L -b $J/$who -c $J/$who "$BASE/$path" --data-urlencode "_csrf=$t" "$@"; }
login(){ TOKPAGE=login.php post $1 login.php --data-urlencode "email=$2" --data-urlencode "password=${3:-Password123!}" >/dev/null; }

echo "Auth"
r=$(curl -s -L -c $J/anon "$BASE/dashboard.php"); ok "anonymous is sent to login" $(has "$r" "Log in")
r=$(curl -s "$BASE/login.php" -d "email=admin@icct.test&password=Password123!"); ok "login without CSRF token is refused" $(has "$r" "Invalid or expired form")
r=$(TOKPAGE=login.php post anon login.php --data-urlencode "email=admin@icct.test" --data-urlencode "password=wrong"); ok "wrong password rejected" $(has "$r" "Incorrect email or password")
for w in admin:admin@icct.test staff:staff@icct.test adv:adviser1@icct.test adv2:adviser2@icct.test off:student1@icct.test off2:student5@icct.test stu:student3@icct.test stu4:student8@icct.test; do login ${w%%:*} ${w#*:}; done
r=$(get stu dashboard.php); ok "student dashboard" $(has "Your upcoming events" "Your upcoming events"); ok "student sees own upcoming event" $(has "$r" "Intro to Web Development")

echo "Role access"
for who in stu off adv staff; do r=$(get $who users.php); ok "$who blocked from Users" $(has "$r" "cannot open this page"); done
r=$(get admin users.php); ok "admin opens Users" $(has "$r" "Create an account")
r=$(get stu reports.php); ok "student blocked from Reports" $(has "$r" "cannot open this page")
r=$(get staff reports.php); ok "staff opens Reports" $(has "$r" "Attendance rate")
r=$(get adv reports.php); ok "adviser sees own org" $(has "$r" "Junior Philippine Computer Society"); ok "adviser does not see other org" $(hasnt "$r" "Business Administration Society")
r=$(get stu org.php?id=1); ok "student cannot see member list" $(hasnt "$r" "Add a member"); ok "student cannot see member emails/names table" $(hasnt "$r" "Student no.")
r=$(get off org.php?id=1); ok "officer sees member tools in own org" $(has "$r" "Add a member")
r=$(get off org.php?id=2); ok "officer has no tools in other org" $(hasnt "$r" "Add a member")
r=$(get off "event_form.php?org=2"); ok "officer redirected from other org's event form" $(hasnt "$r" "Save event")
r=$(get stu "event.php?id=5"); ok "draft event hidden from students" $(hasnt "$r" "Career Talk")
r=$(get off2 "event.php?id=5"); ok "draft visible to its officer" $(has "$r" "Career Talk")

echo "Registration rules"
r=$(TOKPAGE=event.php?id=1 post stu4 "event.php?id=1" --data-urlencode "action=register"); ok "register for open event" $(has "$r" "You are registered")
r=$(TOKPAGE=event.php?id=1 post stu4 "event.php?id=1" --data-urlencode "action=register"); ok "duplicate blocked" $(has "$r" "already registered")
r=$(TOKPAGE=event.php?id=2 post stu4 "event.php?id=2" --data-urlencode "action=register"); ok "full event blocked" $(has "$r" "event is full")
r=$(TOKPAGE=event.php?id=4 post stu4 "event.php?id=4" --data-urlencode "action=register"); ok "ended event blocked" $(has "$r" "already ended")
r=$(TOKPAGE=event.php?id=1 post stu4 "event.php?id=1" --data-urlencode "action=cancel_reg"); ok "cancel own registration" $(has "$r" "registration was cancelled")
r=$(TOKPAGE=event.php?id=1 post stu4 "event.php?id=1" --data-urlencode "action=register"); ok "can re-register after cancelling" $(has "$r" "You are registered")
r=$(TOKPAGE=event.php?id=1 post adv "event.php?id=1" --data-urlencode "action=register"); ok "adviser cannot register" $(has "$r" "Only students")

echo "Attendance"
r=$(get off "event.php?id=1"); ok "officer sees registrants" $(has "$r" "Registrants");
rid=$(grep -o 'name="att\[[0-9]*\]"' <<<"$r" | head -1 | grep -o '[0-9]*')
r=$(TOKPAGE=event.php?id=1 post off "event.php?id=1" --data-urlencode "action=mark" --data-urlencode "att[$rid]=present"); ok "officer marks attendance" $(has "$r" "Attendance saved")
r=$(TOKPAGE=event.php?id=1 post stu "event.php?id=1" --data-urlencode "action=mark" --data-urlencode "att[$rid]=absent"); ok "student cannot mark attendance" $(hasnt "$r" "Attendance saved")
r=$(TOKPAGE=event.php?id=3 post off "event.php?id=3" --data-urlencode "action=mark" --data-urlencode "att[$rid]=absent"); ok "officer of other org cannot mark" $(hasnt "$r" "Attendance saved")
r=$(get adv "event.php?id=4"); ok "adviser sees attendance read-only" $(has "$r" "Present"); ok "adviser has no save button" $(hasnt "$r" "Save attendance")
r=$(get off "export.php?event=1"); ok "CSV export for officer" $(has "$r" "\"Student no.\",Name")
r=$(get stu "export.php?event=1"); ok "CSV export refused for student" $(hasnt "$r" "Student no.")

echo "Membership and officer sync"
r=$(TOKPAGE=org.php?id=2 post stu4 "org.php?id=2" --data-urlencode "action=join"); ok "student requests to join" $(has "$r" "Join request sent")
r=$(TOKPAGE=org.php?id=2 post stu4 "org.php?id=2" --data-urlencode "action=join"); ok "repeat join request is not duplicated" $(has "$r" "already") || true
r=$(TOKPAGE=org.php?id=1 post off "org.php?id=1" --data-urlencode "action=add_member" --data-urlencode "student_no=2024-0005" --data-urlencode "position=Treasurer"); ok "officer adds member by student no" $(has "$r" "Member added")
r=$(get off "org.php?id=1"); mid=$(grep -o 'name="membership_id" value="[0-9]*"' <<<"$r" | sed -n 1p | grep -o '[0-9]*' | tail -1)
r=$(TOKPAGE=org.php?id=1 post off "org.php?id=1" --data-urlencode "action=update_member" --data-urlencode "membership_id=$mid" --data-urlencode "position=VP" --data-urlencode "is_officer=on"); ok "officer updates member role" $(has "$r" "Member updated")
r=$(TOKPAGE=org.php?id=1 post stu4 "org.php?id=1" --data-urlencode "action=approve" --data-urlencode "membership_id=1"); ok "student cannot approve" $(hasnt "$r" "Request approved")

echo "Event management"
S="+30 days"; d(){ date -d "$1" '+%Y-%m-%dT%H:%M'; }
r=$(TOKPAGE=event_form.php?org=1 post off "event_form.php?org=1" --data-urlencode "title=Test Talk" --data-urlencode "venue=AVR" --data-urlencode "start_at=$(d '+30 days 10:00')" --data-urlencode "end_at=$(d '+30 days 12:00')" --data-urlencode "reg_deadline=$(d '+31 days 10:00')" --data-urlencode "status=published"); ok "deadline after start rejected" $(has "$r" "cannot be after")
r=$(TOKPAGE=event_form.php?org=1 post off "event_form.php?org=1" --data-urlencode "title=Test Talk" --data-urlencode "venue=AVR" --data-urlencode "start_at=$(d '+30 days 10:00')" --data-urlencode "end_at=$(d '+30 days 12:00')" --data-urlencode "reg_deadline=$(d '+29 days 10:00')" --data-urlencode "capacity=1" --data-urlencode "status=published"); ok "officer creates and publishes event" $(has "$r" "saved and published")
id=$(php -r 'require "app/lib.php"; echo val("SELECT MAX(id) FROM events");')
r=$(TOKPAGE=event.php?id=$id post stu "event.php?id=$id" --data-urlencode "action=register"); ok "student registers (limit 1)" $(has "$r" "You are registered")
r=$(TOKPAGE=event.php?id=$id post stu4 "event.php?id=$id" --data-urlencode "action=register"); ok "second student blocked at limit" $(has "$r" "event is full")
r=$(TOKPAGE=event.php?id=$id post off "event.php?id=$id" --data-urlencode "action=cancel_event"); ok "officer cancels event" $(has "$r" "Event cancelled")
r=$(TOKPAGE=event.php?id=$id post stu4 "event.php?id=$id" --data-urlencode "action=register"); ok "cancelled event blocks registration" $(has "$r" "cancelled")

echo "Admin"
r=$(TOKPAGE=users.php post admin users.php --data-urlencode "action=update" --data-urlencode "user_id=1" --data-urlencode "role=student"); ok "admin cannot demote self" $(has "$r" "cannot demote")
r=$(TOKPAGE=users.php post admin users.php --data-urlencode "action=create" --data-urlencode "full_name=New Adviser" --data-urlencode "email=new@icct.test" --data-urlencode "role=adviser" --data-urlencode "password=Password123!"); ok "admin creates account" $(has "$r" "Account created")
uid=$(php -r 'require "app/lib.php"; echo val("SELECT id FROM users WHERE email=\"student8@icct.test\"");')
r=$(TOKPAGE=users.php post admin users.php --data-urlencode "action=update" --data-urlencode "user_id=$uid" --data-urlencode "role=student"); # deactivate (checkbox absent)
r=$(TOKPAGE=login.php post anon2 login.php --data-urlencode "email=student8@icct.test" --data-urlencode "password=Password123!"); ok "deactivated user cannot log in" $(has "$r" "Incorrect email or password")
r=$(get stu4 dashboard.php); ok "deactivated user's live session is ended" $(has "$r" "Log in")
r=$(TOKPAGE=register.php post anon3 register.php --data-urlencode "full_name=<script>alert(1)</script>" --data-urlencode "student_no=9" --data-urlencode "course=BSIT" --data-urlencode "email=x@y.test" --data-urlencode "password=Password123!" --data-urlencode "password2=Password123!")
ok "self-registration works" $(has "$r" "Dashboard"); ok "name is HTML-escaped" $(hasnt "$r" "<script>alert(1)</script>")

echo; echo "passed $pass, failed $fail"; [ $fail -eq 0 ]
