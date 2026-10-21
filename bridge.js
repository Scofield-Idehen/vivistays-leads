// Connects the Vivistays Leads page to this server instead of claude.ai.
// It provides the same window.claude.use("db" | "sample" | "user" | "downloads") the page expects.
(function () {
  "use strict";
  async function api(action, body) {
    let r;
    try {
      r = await fetch("api.php?action=" + action, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-Requested-With": "fetch" },
        body: JSON.stringify(body || {})
      });
    } catch (e) { throw { code: "network", message: "No connection" }; }
    if (r.status === 401) { location.reload(); throw { code: "not_signed_in" }; }
    let j = {};
    try { j = await r.json(); } catch (e) {}
    if (!r.ok || j.error) throw { code: j.code || "error", message: j.error || "Server error" };
    return j;
  }

  // Live lists: each list refreshes after every change and every 15 seconds,
  // so a colleague's changes show up without reloading.
  // Only tell the page when something actually changed, so the screen you are
  // reading is never rebuilt by the background refresh.
  const subs = {}, last = {};
  async function refresh(col, force) {
    const j = await api("list", { collection: col });
    const sig = JSON.stringify(j.docs);
    if (!force && last[col] === sig) return;
    last[col] = sig;
    const docs = j.docs.map(d => ({ id: d.id, data: () => d.data }));
    (subs[col] || []).forEach(s => s.cb({ docs }));
  }
  setInterval(() => {
    if (document.visibilityState !== "visible") return;
    Object.keys(subs).forEach(c => refresh(c).catch(() => {}));
  }, 15000);

  const db = {
    collection(col) {
      return {
        onSnapshot(cb, err) {
          const s = { cb };
          (subs[col] = subs[col] || []).push(s);
          refresh(col, true).catch(e => { if (err) err(e); });
          return () => { subs[col] = subs[col].filter(x => x !== s); };
        },
        doc(id) {
          return {
            update: async p => { await api("update", { collection: col, id, data: p }); refresh(col).catch(() => {}); },
            set: async d => { await api("set", { collection: col, id, data: d }); refresh(col).catch(() => {}); },
            delete: async () => { await api("delete", { collection: col, id }); refresh(col).catch(() => {}); }
          };
        },
        add: async d => { const j = await api("add", { collection: col, data: d }); refresh(col).catch(() => {}); return { id: j.id }; }
      };
    }
  };

  const sample = async (input, opts) => {
    const prompt = typeof input === "string" ? input : input.map(m => m.content).join("\n\n");
    const j = await api("ai", { prompt, tier: (opts && opts.modelTier) || "default" });
    return { text: j.text || "", truncated: j.stop === "max_tokens" };
  };
  // Read the JSON in an AI reply. Fixes raw line breaks inside strings, and
  // asks once more if the reply still can't be read.
  function readJson(text) {
    const m = (text || "").replace(/```(?:json)?/g, "").match(/\{[\s\S]*\}/);
    if (!m) return null;
    try { return JSON.parse(m[0]); } catch (e) {}
    let out = "", inStr = false, esc = false;
    for (const ch of m[0]) {
      if (inStr) {
        if (esc) { esc = false; out += ch; continue; }
        if (ch === "\\") { esc = true; out += ch; continue; }
        if (ch === '"') { inStr = false; out += ch; continue; }
        if (ch === "\n") { out += "\\n"; continue; }
        if (ch === "\r") continue;
        if (ch === "\t") { out += "\\t"; continue; }
        out += ch;
      } else { if (ch === '"') inStr = true; out += ch; }
    }
    try { return JSON.parse(out); } catch (e) { return null; }
  }
  sample.json = async (input, opts) => {
    let lastCut = false;
    for (let attempt = 0; attempt < 2; attempt++) {
      const r = await sample(input + "\n\nRespond with the JSON only, no other text. Escape line breaks inside strings as \\n.", opts);
      lastCut = r.truncated;
      const j = r.truncated ? null : readJson(r.text);
      if (j) return j;
      console.warn("AI reply could not be read (attempt " + (attempt + 1) + ")", r.text);
    }
    throw { code: "bad_json", message: lastCut ? "The AI reply was cut off before it finished" : "The AI reply wasn't readable" };
  };

  const me = window.VL_USER || "user";
  const user = { id: async () => me, me: async () => ({ id: me, name: me }), isOwner: () => true, canEdit: () => true, can: () => true };

  // Save a file to the computer (used by "Save flyer").
  const downloads = {
    save: async ({ filename, data, mimeType }) => {
      const blob = data instanceof Blob ? data : new Blob([data], { type: mimeType || "application/octet-stream" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url; a.download = filename || "download"; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 4000);
      return { saved: true };
    }
  };

  // Stayful landlord leads: ask the server to check for new ones.
  // Posts photo library: upload / remove, then refresh the list.
  window.VL_photoUpload = async body => { const r = await api("photo_upload", body); refresh("photos", true).catch(() => {}); return r; };
  window.VL_photoDelete = async id => { const r = await api("photo_delete", { id }); refresh("photos", true).catch(() => {}); return r; };

  window.VL_sync = async force => {
    const r = await api("stayful", { force: !!force });
    if (r.added || r.updated) refresh("leads", true).catch(() => {});
    return r;
  };

  window.claude = {
    use: async name => name === "db" ? db : name === "sample" ? (window.VL_AI ? sample : null) : name === "user" ? user : name === "downloads" ? downloads : null
  };
})();
