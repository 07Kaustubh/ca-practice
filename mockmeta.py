"""Stand-in for Meta's WhatsApp Cloud API.

The previous version was eleven lines and ALWAYS returned HTTP 200. That is why
wa_fanout.py's error handling was never exercised and why a bug that retries
permanent failures survived every run of the suite. A mock that cannot fail is
the same defect as a gate that cannot fail.

Shapes here are taken from Meta's own documentation: the success envelope and the
error envelope are reproduced field for field. Two fidelity notes that matter:

  - `error_subcode` is documented as DEPRECATED and not returned in v16.0+, so it
    is omitted. Code that keys on it is keying on something Meta stopped sending.
  - `message` is only "the code and its title", and Meta explicitly warns not to
    build logic on titles. It is included for realism; nothing should parse it.

Inject a failure with  CA_MOCK_FAIL=<code>  e.g. CA_MOCK_FAIL=131026
Force a run of mixed outcomes with  CA_MOCK_FAIL=mixed
"""
import json, os, http.server, random

# details strings verbatim from Meta's error tables
ERRORS = {
    130429: ("Rate limit hit", "Cloud API message throughput has been reached."),
    131056: ("(#131056) Too many requests",
             "Too many messages sent from the sender phone number to the same recipient "
             "phone number in a short period of time."),
    131000: ("Something went wrong", "Message failed to send due to an unknown error."),
    131016: ("Service unavailable", "A service is temporarily unavailable."),
    133004: ("Server unavailable", "Server is temporarily unavailable."),
    131057: ("Account in maintenance mode", "Business Account is in maintenance mode"),
    80007:  ("Rate limit issues", "The WhatsApp Business Account has reached its rate limit."),
    4:      ("Application request limit reached", "The app has reached its API call rate limit."),
    2:      ("Service temporarily unavailable", "Temporary due to downtime or due to being overloaded."),
    # ---- permanent ----
    131026: ("Message undeliverable",
             "Unable to deliver message. Reasons can include: The recipient phone number is "
             "not a WhatsApp phone number."),
    131047: ("Re-engagement message",
             "More than 24 hours have passed since the recipient last replied to the sender number."),
    131050: ("User stopped marketing messages",
             "Unable to deliver the message. This recipient has chosen to stop receiving "
             "marketing messages on WhatsApp from your business."),
    130403: ("Business blocked user", "Unable to deliver the message. This business has blocked the end user on WhatsApp"),
    132001: ("Template does not exist",
             "The template does not exist in the specified language or the template has not been approved."),
    132015: ("Template paused", "Template is paused due to low quality so it cannot be sent in a template message."),
    100:    ("Invalid parameter", "The request included one or more unsupported or misspelled parameters."),
    # ---- account level: every message fails until a human acts ----
    190:    ("Access token has expired", "Your access token has expired."),
    131031: ("Account has been locked",
             "The WhatsApp Business Account associated with the app has been restricted or disabled "
             "for violating a platform policy."),
    133010: ("Phone number not registered", "Phone number not registered on the WhatsApp Business Platform."),
    131042: ("Business eligibility payment issue", "There was an error related to your payment method."),
}

MIXED = [131026, 130429, 131047, None, 131056, None, 132001]
_n = [0]


class H(http.server.BaseHTTPRequestHandler):
    def _json(self, status, body):
        raw = json.dumps(body).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_POST(self):
        n = int(self.headers.get('Content-Length', 0))
        b = self.rfile.read(n)
        try:
            body = json.loads(b)
        except Exception:
            body = {}
        open('/tmp/wa_capture.json', 'ab').write(
            json.dumps({"path": self.path, "body": body}).encode() + b'\n')

        mode = os.environ.get('CA_MOCK_FAIL', '').strip()
        code = None
        if mode == 'mixed':
            code = MIXED[_n[0] % len(MIXED)]; _n[0] += 1
        elif mode.isdigit():
            code = int(mode)

        if code and code in ERRORS:
            title, details = ERRORS[code]
            # Meta does NOT document HTTP status for Cloud API messages and tells you
            # to branch on error.code. 400 is used here only so something is on the
            # wire; nothing downstream may depend on it.
            return self._json(400, {"error": {
                "message": "(#%d) %s" % (code, title),
                "type": "OAuthException",
                "code": code,
                "error_data": {"messaging_product": "whatsapp", "details": details},
                "fbtrace_id": "Mock%08x" % random.getrandbits(32),
            }})

        return self._json(200, {
            "messaging_product": "whatsapp",
            "contacts": [{"input": body.get("to", ""), "wa_id": str(body.get("to", "")).lstrip("+")}],
            # a 200 means ACCEPTED, not delivered - delivery arrives by webhook
            "messages": [{"id": "wamid.MOCK%016X" % random.getrandbits(64),
                          "message_status": "accepted"}],
        })

    def log_message(self, *a):
        pass


http.server.HTTPServer(('127.0.0.1', 9099), H).serve_forever()
