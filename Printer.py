#!/usr/bin/python
# -*- coding: UTF-8 -*-
# HalloChristkind.py von Fabian Beiner

import os, serial, sqlite3, struct, sys, textwrap, time, unicodedata

class Printer(object):
	COMMAND = 0x1B

	def __init__(self):
		try:
			self.serialPort = serial.Serial(
				port = '/dev/ttyUSB0',
				baudrate = 9600,
				bytesize = serial.EIGHTBITS,
				parity = serial.PARITY_NONE,
				stopbits = serial.STOPBITS_ONE,
				timeout = None,
				xonxoff = False#,
				#dsrdtr = True
			)

			if self.serialPort.isOpen() == False:
				self.serialPort.open()
		except:
			print "-=> ERROR: Konnte die Verbindung zum Drucker nicht herstellen!"
			sys.exit(1)

		try:
			self.sql = sqlite3.connect("HalloChristkind.s3db")
		except sqlite3.Error, e:
			print "-=> ERROR: %s" % e.args[0]
			sys.exit(1)

		print "-=> INFO: Drucker bereit!"

	def __del__(self):
		try:
			self.serialPort.close()
		except AttributeError:
			pass
		print "-=> INFO: Bye-bye!"

	# Funktionen zur Positionierung des Textes
	def centerText(self, sText):
		return sText.strip().center(48)

	def leftText(self, sText):
		return sText.strip()

	# Funktionen um Bold & Underline zu aktivieren und deaktivieren
	def enableBold(self):
		self.sCommand = struct.pack('BBB', self.COMMAND, 0x47, 0x01)
		self.serialPort.write(self.sCommand)
		time.sleep(0.1)

	def disableBold(self):
		self.sCommand = struct.pack('BBB', self.COMMAND, 0x47, 0x00)
		self.serialPort.write(self.sCommand)
		time.sleep(0.1)

	def enableUnderline(self):
		self.sCommand = struct.pack('BBB', self.COMMAND, 0x2D, 1)
		self.serialPort.write(self.sCommand)
		time.sleep(0.1)

	def disableUnderline(self):
		self.sCommand = struct.pack('BBB', self.COMMAND, 0x2D, 0)
		self.serialPort.write(self.sCommand)
		time.sleep(0.1)

	def feed(self):
		self.write("     ")
		self.write(self.centerText("*"))
		self.write(" ")
		self.write(self.centerText("- * -"))
		self.write(" ")
		self.write(self.centerText("*"))
		self.write("     ")
		time.sleep(0.5)

	def partialCut(self):
		self.sCommand = struct.pack('BB', self.COMMAND, 0x6D)
		self.serialPort.write(self.sCommand)
		time.sleep(0.1)

	def printWish(self, sFrom, sWish):
		sDate = time.strftime("%d. %B %Y", time.localtime(time.time()))
		sTime = time.strftime("%H:%M", time.localtime(time.time()))
		self.write(self.centerText(sTime + " Uhr am " + sDate))
		self.enableUnderline()
		self.enableBold()
		self.write(self.centerText(sFrom.strip()))
		self.disableUnderline()
		self.disableBold()
		self.write(" ")
		lText = textwrap.wrap(sWish, 48)
		for sText in lText:
			self.write(sText)
			time.sleep(0.5)
		self.feed()
		time.sleep(0.5)
		#self.partialCut()

	def fetchWishes(self):
		cur = self.sql.cursor()
		cur.execute("SELECT uid, name, wish, network FROM wishes WHERE printed = 0 ORDER BY wish_date ASC")
		rows = cur.fetchall()
		for row in rows:
			uid = unicodedata.normalize('NFKD', row[0]).encode('ascii','ignore')
			fullname = unicodedata.normalize('NFKD', row[1]).encode('ascii','ignore')
			wish = unicodedata.normalize('NFKD', row[2]).encode('ascii','ignore')
			print "-=> INFO: Drucke Zettel von " + fullname + " ..."
			self.printWish(fullname, wish)
			cur.execute("UPDATE wishes SET printed=1 WHERE uid=?", [uid])
			self.sql.commit()

	def write(self, sText):
		self.serialPort.write(sText + "\n")
		time.sleep(0.3)

if __name__ == '__main__':
	Printer = Printer()
	while True:
		try:
			Printer.fetchWishes()
			time.sleep(10)
		except:
			sys.exit(1)
