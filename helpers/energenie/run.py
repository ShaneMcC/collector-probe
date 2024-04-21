#!/usr/bin/python

# Based on energenie monitor.py this is a script that watches for data from
# energenie devices and writes it as json to a file.
#
# Only the most recent information is stored per device, and data is removed
# if it is older than 20 seconds.

import time,os,json,getopt,signal

import sys
sys.path.append(os.path.dirname(__file__) + '/pyenergenie/src')
from energenie import OpenThings, Devices, Messages, radio

directory = {"__META": {"time": int(time.time())}}
outputFile = None

def getData(msg):
    result = {}

    for rec in msg['recs']:
        paramid = rec['paramid']
        try:
            value = rec['value']
        except:
            value = None

        if OpenThings.param_info.has_key(paramid):
            result[OpenThings.param_info[paramid]['n']] = value
        else:
            result['UNKNOWN_%s' % hex(paramid)] = value

    return result

def updateDirectory(message):
    # Add the data from the most recent message into our storage directory.
    header = message['header']
    mfrid = header['mfrid']
    productid = header['productid']
    sensorid = header['sensorid']

    deviceID = "OT_%s-%s-%s" % (mfrid, productid, sensorid)

    if not directory.has_key(deviceID):
        directory[deviceID] = {}
        directory[deviceID]['Description'] = Devices.getDescription(header["mfrid"], header["productid"])

    directory[deviceID]["time"] = int(time.time())
    directory[deviceID]["data"] = getData(message)

    directory["__META"]["time"] = directory[deviceID]["time"]

def tidyDirectory():
    # Remove old data from directory, ie devices that are turned off.
    limit = int(time.time() - 20)
    for device in directory.keys():
        if int(directory[device]['time']) < limit:
            del directory[device]

def dumpDirectory():
    if outputFile == None:
        print json.dumps(directory)
    else:
        with open(outputFile, 'w') as outfile:
            json.dump(directory, outfile)
        os.chmod(outputFile, 0777)

def send_join_ack(mfrid, productid, sensorid):
    # send back a JOIN ACK, so that join light stops flashing
    response = OpenThings.alterMessage(Messages.JOIN_ACK,
        header_mfrid=mfrid,
        header_productid=productid,
        header_sensorid=sensorid)
    p = OpenThings.encode(response)
    radio.transmitter()
    radio.transmit(p)
    radio.receiver()


def monitor_loop():
    radio.receiver()

    while True:
        if radio.isReceiveWaiting():
            payload = radio.receive()
            try:
                decoded = OpenThings.decode(payload)
            except OpenThings.OpenThingsException as e:
                continue

            updateDirectory(decoded)
            tidyDirectory()
            dumpDirectory()

            # Process any JOIN messages by sending back a JOIN-ACK to turn the LED off
            if len(decoded["recs"]) != 0:
                if decoded["recs"][0]["paramid"] == OpenThings.PARAM_JOIN:
                    header    = decoded["header"]
                    mfrid     = header["mfrid"]
                    productid = header["productid"]
                    sensorid  = header["sensorid"]
                    send_join_ack(mfrid, productid, sensorid)

def doNothing(msg):
    pass

def main():
    global outputFile, directory
    try:
        opts, args = getopt.getopt(sys.argv[1:],"ho:",["output="])
    except getopt.GetoptError:
        print '%s -o <outputfile>' % sys.argv[0]
        sys.exit(2)

    for opt, arg in opts:
      if opt == '-h':
         print '%s -o <outputfile>' % sys.argv[0]
         sys.exit()
      elif opt in ("-o", "--output"):
         outputFile = arg

    if outputFile == None:
        print "No output file specified, output to console."

    radio.init()
    OpenThings.init(Devices.CRYPT_PID)

    try:
        dumpDirectory()
        monitor_loop()

    finally:
        radio.finished()
        directory = {"__META": {"time": int(time.time())}}
        dumpDirectory()

def sigterm_handler(_signo, _stack_frame):
    radio.finished()
    directory = {"__META": {"time": int(time.time())}}
    dumpDirectory()
    sys.exit(0)

if __name__ == "__main__":
    # Library is echoy, we don't want that...
    radio.trace = doNothing;

    # Handle kill signal
    signal.signal(signal.SIGTERM, sigterm_handler)

    # Now begin!
    main()
