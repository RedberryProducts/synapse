import argparse
import http.cookiejar
import json
import sys
import time
import urllib.parse
import urllib.request
import uuid
from pathlib import Path
parser = argparse.ArgumentParser(description='Qualify Synapse over HTTP using the runtime fixtures.')
parser.add_argument('base_url')
parser.add_argument('label')
parser.add_argument('--output', required=True)
parser.add_argument('--buffered', action='store_true', help='Expect buffered delivery (RoadRunner).')
args = parser.parse_args()
base = args.base_url.rstrip('/')
label = args.label
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

def get(path):
    with client.open(base + path, timeout=30) as response:
        return response.read()

def stream(path, data):
    token = next((urllib.parse.unquote(c.value) for c in jar if c.name == 'XSRF-TOKEN'))
    req = urllib.request.Request(base + path, json.dumps(data).encode(), {'Content-Type': 'application/json', 'Accept': 'text/event-stream', 'X-XSRF-TOKEN': token})
    start = time.monotonic()
    pending = b''
    events = []
    chunks = []
    with client.open(req, timeout=30) as response:
        status = response.status
        while True:
            chunk = response.read1(65536)
            if not chunk:
                break
            elapsed = round(time.monotonic() - start, 3)
            chunks.append([elapsed, len(chunk)])
            pending += chunk
            while b'\n\n' in pending:
                frame, pending = pending.split(b'\n\n', 1)
                for line in frame.splitlines():
                    if line.startswith(b'data: '):
                        payload = line[6:]
                        if payload != b'[DONE]':
                            events.append([elapsed, json.loads(payload)])
    return {'status': status, 'elapsed': round(time.monotonic() - start, 3), 'chunks': chunks, 'events': events}

def event(run, kind):
    return next((e for t, e in run['events'] if e['type'] == kind))

def end(run):
    return event(run, 'data-synapse-end')['data']

def send(marker, slug='runtime-agent', conversation=None):
    data = {'message': marker}
    if conversation:
        data['conversation_id'] = conversation
    return stream('/synapse/api/chat/app.ai.agents.' + slug + '/send', data)
report = {'label': label, 'base': base, 'checks': {}, 'runs': {}}
try:
    report['info'] = json.loads(get('/runtime-info'))
    page = get('/synapse')
    assert b'window.Synapse' in page
    agents = json.loads(get('/synapse/api/agents'))
    assert 'app.ai.agents.runtime-agent' in json.dumps(agents)
    assert 'app.ai.agents.runtime-approval-agent' in json.dumps(agents)
    report['checks']['dashboard_and_discovery'] = True
    marker = label + '-' + uuid.uuid4().hex[:8]
    run = send(marker)
    report['runs']['success'] = run
    result = end(run)
    assert result['status'] == 'completed', result
    turn = result['turn']
    msg = turn['message']
    assert marker in msg['content'] and 'users=1;done' in msg['content'], msg['content']
    assert len(turn['tool_invocations']) == 1, turn['tool_invocations']
    assert turn['tool_invocations'][0]['result'] == 'tool-result:' + marker
    assert len(msg['step_details']) == 2, msg['step_details']
    assert len(msg['sources']) == 1, msg['sources']
    report['checks']['tools_steps_sources_completion'] = True
    times = {}
    for t, e in run['events']:
        times.setdefault(e['type'], t)
    report['timing'] = {k: times.get(k) for k in ['data-synapse-start', 'tool-input-available', 'tool-output-available', 'text-delta', 'data-synapse-end']}
    report['checks']['incremental_stream'] = times['tool-input-available'] < 1 and times['tool-output-available'] - times['tool-input-available'] > 1.5 and (times['data-synapse-end'] - times['text-delta'] > 0.7)
    cid = event(run, 'data-synapse-start')['data']['conversationId']
    history = json.loads(get('/synapse/api/conversations/' + cid))
    assert marker in json.dumps(history)
    follow = send('recall-' + marker, conversation=cid)
    report['runs']['followup'] = follow
    assert 'users=2;done' in end(follow)['turn']['message']['content']
    report['checks']['history_and_replay'] = True
    failure = send('fail-' + marker)
    report['runs']['failure'] = failure
    assert end(failure)['status'] == 'failed'
    assert 'fixture failure:' in event(failure, 'error')['errorText']
    recovery = send('recovery-' + marker)
    report['runs']['recovery'] = recovery
    assert end(recovery)['status'] == 'completed'
    assert 'users=1;done' in end(recovery)['turn']['message']['content']
    assert len(end(recovery)['turn']['tool_invocations']) == 1
    report['checks']['failure_then_clean_request'] = True
    for decision in ['approve', 'deny']:
        paused = send(decision + '-' + marker, 'runtime-approval-agent')
        report['runs'][decision + '-paused'] = paused
        saved = end(paused)
        approval = saved['turn']['message']['approval']
        assert saved['status'] == 'paused', saved['status']
        pcid = event(paused, 'data-synapse-start')['data']['conversationId']
        assert 'pending' in get('/synapse/api/conversations/' + pcid).decode()
        decisions = {r['id']: decision for r in approval['requests']}
        resumed = stream('/synapse/api/chat/app.ai.agents.runtime-approval-agent/messages/' + saved['assistantMessageId'] + '/approvals', {'revision': approval['revision'], 'decisions': decisions})
        report['runs'][decision + '-resumed'] = resumed
        assert end(resumed)['status'] == 'completed', end(resumed)['status']
        tools = end(resumed)['turn']['tool_invocations']
        assert len(tools) == 1, tools
        assert tools[0]['status'] == ('success' if decision == 'approve' else 'denied'), tools
    report['checks']['approval_reload_approve_deny'] = True
    report['final_info'] = json.loads(get('/runtime-info'))
    assert report['checks']['incremental_stream'] == (not args.buffered), 'Unexpected stream delivery timing'
    assert report['info']['streaming'] == (not args.buffered), 'Dashboard streaming flag disagrees with runtime'
    report['checks']['expected_delivery_and_dashboard_flag'] = True
except Exception as exc:
    report['error'] = type(exc).__name__ + ': ' + str(exc)
finally:
    Path(args.output).write_text(json.dumps(report, indent=2))
    print(json.dumps({k: v for k, v in report.items() if k != 'runs'}, indent=2))
if 'error' in report:
    sys.exit(1)
