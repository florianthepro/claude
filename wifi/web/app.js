/* crack-wifi.local — front-end logic */
(function () {
  "use strict";

  const $ = (id) => document.getElementById(id);
  const api = {
    status: () => fetch("/api/status").then((r) => r.json()),
    scan: () => fetch("/api/scan").then((r) => r.json()),
    attack: (bssid, options) =>
      fetch("/api/attack", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ bssid, options }),
      }).then((r) => r.json()),
    poll: (id, since) =>
      fetch(`/api/attack?id=${encodeURIComponent(id)}&since=${since}`).then((r) => r.json()),
    stop: (id) =>
      fetch("/api/attack/stop", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      }).then((r) => r.json()),
  };

  const state = {
    networks: [],
    selected: null,
    jobId: null,
    since: 0,
    pollTimer: null,
  };

  // -- signal glyph ------------------------------------------------------
  function signalBars(dbm) {
    if (dbm == null) return "····";
    if (dbm >= -55) return "▁▃▅▇";
    if (dbm >= -65) return "▁▃▅ ";
    if (dbm >= -75) return "▁▃  ";
    return "▁   ";
  }

  function lockGlyph(enc) {
    return enc && enc.toUpperCase().startsWith("OPEN") ? "🔓" : "🔒";
  }

  // -- network list ------------------------------------------------------
  function renderNetworks(nets) {
    const list = $("network-list");
    list.innerHTML = "";
    $("net-count").textContent = nets.length ? `${nets.length} found` : "";
    $("scan-hint").classList.toggle("hidden", nets.length > 0);

    nets.forEach((n) => {
      const li = document.createElement("li");
      li.className = "network-item";
      li.dataset.bssid = n.bssid;
      const diff = n.difficulty || { label: "?", score: 0 };
      const diffClass = "diff-" + (diff.label || "").replace(/\s+/g, "");
      li.innerHTML = `
        <span class="ni-signal">${signalBars(n.signal)}</span>
        <span class="ni-main">
          <div class="ni-ssid">${escapeHtml(n.ssid)} <span class="lock">${lockGlyph(n.enc)}</span></div>
          <div class="ni-sub">${escapeHtml(n.enc)} · ch ${n.channel} · ${n.signal != null ? n.signal + " dBm" : "—"}${n.wps ? " · WPS" : ""}</div>
        </span>
        <span class="ni-diff ${diffClass}">${escapeHtml(diff.label)}</span>`;
      li.addEventListener("click", () => selectNetwork(n));
      list.appendChild(li);
    });
  }

  function markActive(bssid) {
    document.querySelectorAll(".network-item").forEach((el) => {
      el.classList.toggle("active", el.dataset.bssid === bssid);
    });
  }

  // -- detail / difficulty ----------------------------------------------
  function selectNetwork(n) {
    state.selected = n;
    markActive(n.bssid);
    $("detail-empty").classList.add("hidden");
    $("detail").classList.remove("hidden");
    resetAttackUI();

    $("d-ssid").textContent = n.ssid;
    $("d-bssid").textContent = n.bssid;
    $("d-enc").textContent = n.enc;
    $("d-ch").textContent = "ch " + n.channel;
    $("d-signal").textContent = (n.signal != null ? n.signal + " dBm" : "—") + (n.wps ? " · WPS" : "");

    const d = n.difficulty || { score: 0, label: "?", eta: "", reasons: [] };
    animateGauge(d.score);
    $("d-score").textContent = d.score;
    $("d-label").textContent = d.label;
    $("d-eta").textContent = "ETA: " + d.eta;

    const reasons = $("d-reasons");
    reasons.innerHTML = "";
    (d.reasons || []).forEach((r) => {
      const li = document.createElement("li");
      li.textContent = r;
      reasons.appendChild(li);
    });

    // Pre-select a sensible method.
    if (d.method === "wps-pixie") $("opt-method").value = "wps-pixie";
    else $("opt-method").value = "auto";
  }

  function gaugeColor(score) {
    if (score < 30) return "#46d17f";
    if (score < 55) return "#f0b429";
    if (score < 80) return "#ff8f6b";
    return "#ff5c5c";
  }

  function animateGauge(score) {
    const arc = $("gauge-arc");
    const circ = 2 * Math.PI * 52; // ~327
    const offset = circ * (1 - Math.max(0, Math.min(100, score)) / 100);
    arc.style.strokeDashoffset = offset;
    arc.style.stroke = gaugeColor(score);
  }

  // -- attack ------------------------------------------------------------
  function collectOptions() {
    const method = $("opt-method").value;
    const opts = { method };
    opts.connect = $("opt-connect").checked;
    const wl = $("opt-wordlist").value.trim();
    if (wl) opts.wordlist = wl;
    if (method === "handshake+bruteforce") {
      opts.bruteforce = true;
      opts.charset = $("opt-charset").value;
      opts.length = parseInt($("opt-length").value, 10) || 8;
    }
    // pass through some network context for the backend fallback
    if (state.selected) {
      opts.ssid = state.selected.ssid;
      opts.enc = state.selected.enc;
      opts.channel = state.selected.channel;
    }
    return opts;
  }

  async function startAttack() {
    if (!state.selected) return;
    resetAttackUI();
    $("attack-btn").disabled = true;
    $("stop-btn").classList.remove("hidden");
    setPill("running");

    const res = await api.attack(state.selected.bssid, collectOptions());
    if (res.error) {
      logLine({ t: 0, phase: "error", level: "error", msg: res.error });
      finishUI("failed");
      return;
    }
    state.jobId = res.job_id;
    state.since = 0;
    poll();
  }

  function poll() {
    clearTimeout(state.pollTimer);
    api.poll(state.jobId, state.since).then((snap) => {
      if (snap.error) return;
      (snap.events || []).forEach(logLine);
      state.since = snap.total_events;

      if (snap.status === "running") {
        state.pollTimer = setTimeout(poll, 350);
      } else {
        finishUI(snap.status);
        if (snap.result) showResult(snap.network, snap.result);
      }
    }).catch(() => {
      state.pollTimer = setTimeout(poll, 800);
    });
  }

  async function stopAttack() {
    if (!state.jobId) return;
    await api.stop(state.jobId);
  }

  function finishUI(status) {
    $("attack-btn").disabled = false;
    $("stop-btn").classList.add("hidden");
    setPill(status);
  }

  function showResult(network, result) {
    $("result").classList.remove("hidden");
    $("r-ssid").textContent = (network && network.ssid) || (state.selected && state.selected.ssid) || "";
    $("r-key").textContent = result.key != null ? result.key : "(none — open network)";
    $("r-method").textContent = result.method || "";
    if (result.user) {
      $("r-user-row").classList.remove("hidden");
      $("r-user").textContent = result.user;
    } else {
      $("r-user-row").classList.add("hidden");
    }

    // Connection status.
    const st = $("r-status");
    const cmd = $("r-connect-cmd");
    if (result.connected) {
      st.textContent = "✓ Connected — you are on the network";
      st.className = "result-val ok";
      cmd.classList.add("hidden");
    } else if (result.connect_cmd) {
      st.textContent = "Not connected — run the command below";
      st.className = "result-val warn";
      cmd.textContent = "$ " + result.connect_cmd;
      cmd.classList.remove("hidden");
    } else {
      st.textContent = "—";
      st.className = "result-val";
      cmd.classList.add("hidden");
    }

    $("r-note").textContent = result.note || "";
  }

  // -- console -----------------------------------------------------------
  function logLine(ev) {
    const c = $("console");
    const line = document.createElement("div");
    line.className = "log-line log-" + (ev.level || "info");
    line.innerHTML =
      `<span class="log-t">${(ev.t ?? 0).toFixed(1)}s</span>` +
      `<span class="log-phase">${escapeHtml(ev.phase || "")}</span>` +
      `<span class="log-msg">${escapeHtml(ev.msg || "")}</span>`;
    c.appendChild(line);
    c.scrollTop = c.scrollHeight;
    if (ev.phase) $("phase-tag").textContent = ev.phase;
  }

  function resetAttackUI() {
    clearTimeout(state.pollTimer);
    state.jobId = null;
    state.since = 0;
    $("console").innerHTML = "";
    $("phase-tag").textContent = "";
    $("result").classList.add("hidden");
    $("status-pill").classList.add("hidden");
    $("stop-btn").classList.add("hidden");
    $("attack-btn").disabled = false;
  }

  function setPill(status) {
    const pill = $("status-pill");
    pill.classList.remove("hidden", "pill-running", "pill-success", "pill-failed", "pill-stopped");
    pill.classList.add("pill-" + status);
    pill.textContent = status;
  }

  // -- scan --------------------------------------------------------------
  async function doScan() {
    const btn = $("scan-btn");
    btn.disabled = true;
    btn.textContent = "Scanning…";
    try {
      const res = await api.scan();
      state.networks = res.networks || [];
      // Sort: easiest first (lowest difficulty score).
      state.networks.sort((a, b) => (a.difficulty?.score ?? 100) - (b.difficulty?.score ?? 100));
      renderNetworks(state.networks);
    } catch (e) {
      alert("Scan failed: " + e);
    } finally {
      btn.disabled = false;
      btn.textContent = "Scan networks";
    }
  }

  // -- util --------------------------------------------------------------
  function escapeHtml(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  // -- init --------------------------------------------------------------
  function init() {
    $("scan-btn").addEventListener("click", doScan);
    $("attack-btn").addEventListener("click", startAttack);
    $("stop-btn").addEventListener("click", stopAttack);
    $("opt-method").addEventListener("change", () => {
      // no-op hook for future UI toggles
    });

    api.status().then((s) => {
      const badge = $("mode-badge");
      badge.textContent = s.mode;
      badge.className = "badge " + (s.mode === "real" ? "badge-real" : "badge-demo");
      if (s.wordlist) $("opt-wordlist").placeholder = s.wordlist;
    }).catch(() => {});
  }

  document.addEventListener("DOMContentLoaded", init);
})();
