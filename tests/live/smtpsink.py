# Minimal SMTP sink for the live test: accepts every message and appends it to a file.
import socketserver, sys, time
OUT = sys.argv[1]
class H(socketserver.StreamRequestHandler):
    def w(self, s): self.wfile.write((s + '\r\n').encode()); self.wfile.flush()
    def handle(self):
        self.w('220 sink ESMTP')
        rcpt = []
        while True:
            line = self.rfile.readline()
            if not line: return
            cmd = line.decode(errors='replace').strip()
            u = cmd.upper()
            if u.startswith('EHLO'):
                self.wfile.write(b'250-sink\r\n250-SIZE 52428800\r\n250 8BITMIME\r\n'); self.wfile.flush()
            elif u.startswith('HELO'): self.w('250 sink')
            elif u.startswith('MAIL FROM'): rcpt = []; self.w('250 OK')
            elif u.startswith('RCPT TO'): rcpt.append(cmd[8:]); self.w('250 OK')
            elif u == 'DATA':
                self.w('354 End data with <CR><LF>.<CR><LF>')
                data = []
                while True:
                    l = self.rfile.readline()
                    if l in (b'.\r\n', b'.\n', b''): break
                    data.append(l[1:] if l.startswith(b'..') else l)
                with open(OUT, 'ab') as f:
                    f.write(b'\n=====MAIL ' + time.strftime('%H:%M:%S').encode() + b' ' + ','.join(rcpt).encode() + b'\n' + b''.join(data))
                self.w('250 OK queued')
            elif u == 'RSET': self.w('250 OK')
            elif u == 'NOOP': self.w('250 OK')
            elif u == 'QUIT': self.w('221 bye'); return
            else: self.w('502 not implemented')
socketserver.ThreadingTCPServer.allow_reuse_address = True
socketserver.ThreadingTCPServer(('127.0.0.1', 2525), H).serve_forever()
