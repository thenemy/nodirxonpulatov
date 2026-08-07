const crypto = require('crypto');

function hashValue(value) {
  if (!value) return null;
  const v = String(value).trim().toLowerCase();
  if (!v) return null;
  return crypto.createHash('sha256').update(v).digest('hex');
}

function cleanPhone(raw) {
  if (!raw) return null;
  let digits = String(raw).replace(/\D+/g, '');
  if (!digits) return null;
  if (!digits.startsWith('998')) digits = '998' + digits.replace(/^0+/, '');
  return digits;
}

function clientIp(req) {
  const xff = req.headers['x-forwarded-for'];
  if (xff) return xff.split(',')[0].trim();
  return (req.socket && req.socket.remoteAddress) || '';
}

function getCookie(req, name) {
  const cookies = req.headers.cookie || '';
  const m = cookies.match(new RegExp('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)'));
  return m ? decodeURIComponent(m[2]) : null;
}

module.exports = async (req, res) => {
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('X-Content-Type-Options', 'nosniff');

  if (req.method === 'GET') {
    res.status(200).json({ ok: true, msg: "CAPI endpoint ishlayapti. POST yuboring." });
    return;
  }
  if (req.method !== 'POST') {
    res.status(405).json({ ok: false, error: 'Method Not Allowed' });
    return;
  }

  const PIXEL_ID = process.env.META_PIXEL_ID;
  const ACCESS_TOKEN = process.env.META_ACCESS_TOKEN;
  if (!PIXEL_ID || !ACCESS_TOKEN) {
    res.status(500).json({ ok: false, error: "Server sozlanmagan (PIXEL_ID/TOKEN yo'q)" });
    return;
  }

  const body = req.body && typeof req.body === 'object' ? req.body : {};
  const eventType = (body.eventType || '').trim();
  const allowed = ['PageView', 'Lead', 'CompleteRegistration'];
  if (!allowed.includes(eventType)) {
    res.status(400).json({ ok: false, error: "Noto'g'ri eventType" });
    return;
  }

  const eventId = (body.event_id && String(body.event_id).replace(/[^A-Za-z0-9_-]/g, ''))
    || crypto.randomBytes(16).toString('hex');

  const fbp = body.fbp || getCookie(req, '_fbp') || null;
  const fbc = body.fbc || getCookie(req, '_fbc') || null;

  const userData = {
    client_ip_address: clientIp(req),
    client_user_agent: req.headers['user-agent'] || '',
  };
  if (fbp) userData.fbp = fbp;
  if (fbc) userData.fbc = fbc;

  if (eventType === 'Lead') {
    const phone = cleanPhone(body.phone);
    if (phone) userData.ph = crypto.createHash('sha256').update(phone).digest('hex');
    const fn = hashValue(body.name);
    if (fn) userData.fn = fn;
  }

  const event = {
    event_name: eventType,
    event_time: Math.floor(Date.now() / 1000),
    event_id: eventId,
    action_source: 'website',
    event_source_url: body.event_source_url || req.headers.referer || '',
    user_data: userData,
  };

  const payload = { data: [event] };
  if (body.test_event_code) payload.test_event_code = body.test_event_code;

  try {
    const graphRes = await fetch(
      `https://graph.facebook.com/v21.0/${PIXEL_ID}/events?access_token=${encodeURIComponent(ACCESS_TOKEN)}`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }
    );
    const fbJson = await graphRes.json().catch(() => null);
    res.status(200).json({ ok: graphRes.ok, event_id: eventId, fb_status: graphRes.status, fb_response: fbJson });
  } catch (err) {
    res.status(200).json({ ok: false, event_id: eventId, error: err.message });
  }
};
