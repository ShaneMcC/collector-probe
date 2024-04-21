#!/bin/sh
### BEGIN INIT INFO
# Provides:          energenie-listen
# Required-Start:    $remote_fs $syslog
# Required-Stop:     $remote_fs $syslog
# Default-Start:     2 3 4 5
# Default-Stop:      0 1 6
# Short-Description: Energenie Listen Service
# Description:       Energenie Listen Service
### END INIT INFO

# Where the script lives
DIR="/home/pi/collector-probe/helpers/energenie/"

# Add any command line options for your daemon here
OUTPUT_FILE="/tmp/energenie-listen.json"

# This next line determines what user the script runs as.
DAEMON_USER=root

if [ -e /etc/default/energenie-listen ]; then
	. /etc/default/energenie-listen
fi;

# The process ID of the script when it runs is stored here:
DAEMON_NAME=energenie-listen
PIDFILE="/var/run/$DAEMON_NAME.pid"

. /lib/lsb/init-functions

do_start () {
    log_daemon_msg "Starting system $DAEMON_NAME daemon"
    start-stop-daemon --start --background --pidfile "${PIDFILE}" --make-pidfile --user "${DAEMON_USER}" --chuid "${DAEMON_USER}" --startas "${DIR}/run.py" -- -o "${OUTPUT_FILE}"
    log_end_msg $?
}
do_stop () {
    log_daemon_msg "Stopping system $DAEMON_NAME daemon"
    start-stop-daemon --stop --signal TERM --pidfile "${PIDFILE}" --retry 10
    log_end_msg $?
}

case "$1" in

    start|stop)
        do_${1}
        ;;

    restart|reload|force-reload)
        do_stop
        do_start
        ;;

    status)
        status_of_proc "$DAEMON_NAME" "${DIR}/run.py" && exit 0 || exit $?
        ;;

    *)
        echo "Usage: /etc/init.d/${DAEMON_NAME} {start|stop|restart|status}"
        exit 1
        ;;

esac
exit 0
