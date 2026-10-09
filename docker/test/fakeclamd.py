# Minimal clamd INSTREAM/PING responder for the live test (real signatures need egress).
import socketserver, struct
EICAR=b'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
class H(socketserver.BaseRequestHandler):
    def handle(self):
        c=self.request; buf=b''
        while not buf.endswith(b'\0') and len(buf)<64:
            ch=c.recv(1)
            if not ch: return
            buf+=ch
        cmd=buf.strip(b'\0').lstrip(b'z')
        if cmd==b'PING': c.sendall(b'PONG\0'); return
        if cmd!=b'INSTREAM': c.sendall(b'UNKNOWN COMMAND\0'); return
        data=b''
        while True:
            n=b''
            while len(n)<4:
                ch=c.recv(4-len(n))
                if not ch: return
                n+=ch
            size=struct.unpack('>I',n)[0]
            if size==0: break
            while size:
                ch=c.recv(min(size,65536)); data+=ch; size-=len(ch)
        c.sendall(b'stream: Eicar-Test-Signature FOUND\0' if EICAR in data else b'stream: OK\0')
socketserver.ThreadingTCPServer.allow_reuse_address=True
socketserver.ThreadingTCPServer(('0.0.0.0',3310),H).serve_forever()
