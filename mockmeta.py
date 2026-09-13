import json,http.server
class H(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        n=int(self.headers.get('Content-Length',0)); b=self.rfile.read(n)
        open('/tmp/wa_capture.json','ab').write(json.dumps(
            {"path":self.path,"body":json.loads(b)}).encode()+b'\n')
        self.send_response(200); self.send_header('Content-Type','application/json'); self.end_headers()
        self.wfile.write(json.dumps({"messaging_product":"whatsapp",
            "messages":[{"id":"wamid.MOCK_"+str(abs(hash(b))%10**8)}]}).encode())
    def log_message(self,*a): pass
http.server.HTTPServer(('127.0.0.1',9099),H).serve_forever()
