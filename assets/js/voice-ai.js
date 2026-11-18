/* همکار پلاس — دستیار صوتی هوش مصنوعی
 * ضبط صدا با MediaRecorder + موج زنده + تبدیل گفتار فارسی به متن (Web Speech fa-IR)
 * ارسال صدا به سرور برای پردازش رایگان (Groq / Gemini / Pollinations / موتور داخلی)
 */
(function (global) {
  'use strict';

  var FAT = window.JalaliJS ? window.JalaliJS.toFa : function (x) { return String(x); };

  var VoiceAI = {
    api: null,          // تابع ارسال درخواست (از app.js تزریق می‌شود)
    options: {},
    stream: null,
    recorder: null,
    chunks: [],
    audioBlob: null,
    audioUrl: null,
    analyser: null,
    vad: null,
    audioCtx: null,
    rafId: null,
    timerId: null,
    seconds: 0,
    recording: false,
    recognition: null,
    recActive: false,
    finalTranscript: '',
    interimTranscript: '',
    previewTasks: [],
    previewMeta: null,
    pendingAutoCreate: false,
    lastSpeechError: '',
    browserSpeechGotResult: false,
    puterLoading: null,

    tpl: function () { return ''; },

    init: function (opts) {
      this.api = opts.api;
      this.options = opts;
      this.build();
      this.bind();
    },

    build: function () {
      if (document.getElementById('modalVoice')) return;
      var users = (this.options.users || []).map(function (u) {
        return '<option value="' + u.id + '">' + esc(u.full_name) + (u.department_name ? ' — ' + esc(u.department_name) : '') + '</option>';
      }).join('');
      var projects = (this.options.projects || []).map(function (p) {
        return '<option value="' + p.id + '">' + esc(p.name) + '</option>';
      }).join('');

      var html = '' +
        '<div class="modal-backdrop" id="modalVoice">' +
        '  <div class="modal modal-lg">' +
        '    <div class="modal-head">' +
        '      <h3>🎙️ دستیار صوتی هوش مصنوعی</h3>' +
        '      <button class="icon-btn" data-voice-close title="بستن">✕</button>' +
        '    </div>' +
        '    <div class="alert alert-info small">✨ به فارسی صحبت کنید؛ مثلاً: «فردا ساعت ۱۰ صبح جلسه با تیم بازاریابی برای پروژه وب‌سایت، اولویت بالا، با سارا». هوش مصنوعی خودش موضوع، روز شمسی، ساعت، مسئول و زیرتسک‌ها را تشخیص می‌دهد.</div>' +
        '    <div class="voice-recorder">' +
        '      <canvas class="wave-canvas" id="waveCanvas" width="800" height="90"></canvas>' +
        '      <div class="rec-timer" id="recTimer">۰۰:۰۰</div>' +
        '      <button type="button" class="rec-btn" id="recBtn" title="شروع/پایان ضبط">🎙️</button>' +
        '      <div class="small muted" id="recStatus" style="margin-top:8px">برای شروع ضبط، روی میکروفن بزنید.</div>' +
        '      <div id="speechHint" class="small muted" style="margin-top:4px"></div>' +
        '    </div>' +
        '    <div id="audioPlayWrap" class="hidden" style="margin:12px 0;text-align:center">' +
        '      <audio id="voiceAudio" controls class="voice-player" style="max-width:100%"></audio>' +
        '    </div>' +
        '    <div class="toolbar" style="margin:12px 0">' +
        '      <label class="btn btn-sm" style="cursor:pointer">📁 آپلود فایل صوتی<input type="file" id="voiceFileInput" accept="audio/*" class="hidden"></label>' +
        '      <button type="button" class="btn btn-sm" id="btnTranscribeTry">📝 تشخیص گفتار مرورگر</button>' +
        '      <button type="button" class="btn btn-sm" id="btnBrowserStt" title="تبدیل فایل صوتی به متن بدون هیچ کلید، مستقیماً در مرورگر">🎧 تبدیل صدا به متن بدون کلید</button>' +
        '      <span class="small muted">یا متن فرمان را بنویسید:</span>' +
        '    </div>' +
        '    <label class="field">' +
        '      <span>متن فرمان صوتی (قابل ویرایش)</span>' +
        '      <textarea id="voiceTranscript" rows="3" placeholder="مثلاً: پس‌فردا ساعت ۹ و نیم صبح تماس با مشتری برای پروژه فروش، اولویت فوری، زیرتسک: آماده‌سازی فایل قیمت، ارسال ایمیل پیگیری"></textarea>' +
        '    </label>' +
        '    <div class="checkline">' +
        '      <input type="checkbox" id="voiceAutoCreate">' +
        '      <label for="voiceAutoCreate">ثبت خودکار تسک‌ها بدون نمایش پیش‌نمایش</label>' +
        '    </div>' +
        '    <div class="toolbar" style="margin-top:6px">' +
        '      <button type="button" class="btn btn-primary" id="btnVoiceProcess">🤖 پردازش هوشمند</button>' +
        '      <button type="button" class="btn" id="btnVoiceReset">🔄 پاک‌سازی</button>' +
        '      <button type="button" class="btn" id="btnVoiceManual" data-voice-close>✍️ ثبت دستی تسک</button>' +
        '    </div>' +
        '    <div id="voiceProcessing" class="loading hidden"><div class="spinner"></div>در حال پردازش صدا با هوش مصنوعی رایگان...</div>' +
        '    <div id="voicePreviewWrap" class="hidden">' +
        '      <div class="toolbar" style="justify-content:space-between">' +
        '        <span class="provider-tag" id="voiceProvider">—</span>' +
        '        <span class="small muted">پیش‌نمایش را بررسی و در صورت نیاز ویرایش کنید.</span>' +
        '      </div>' +
        '      <div id="voicePreviewList"></div>' +
        '      <div class="toolbar">' +
        '        <button type="button" class="btn btn-primary btn-lg" id="btnVoiceConfirm">✅ ثبت تسک‌ها در تقویم شمسی</button>' +
        '        <button type="button" class="btn" id="btnVoiceReprocess">🔁 پردازش مجدد متن</button>' +
        '      </div>' +
        '    </div>' +
        '    <template id="voiceTaskTpl">' +
        '      <div class="ai-preview-card" data-idx="">' +
        '        <div class="toolbar" style="margin-bottom:6px"><h5 style="flex:1">تسک <span class="tnum"></span></h5>' +
        '          <button type="button" class="icon-btn" data-remove title="حذف از لیست">🗑️</button></div>' +
        '        <label class="field"><span>عنوان تسک</span><input type="text" data-f="title"></label>' +
        '        <div class="form-row">' +
        '          <label class="field"><span>تاریخ سررسید (شمسی)</span><input type="text" data-f="due_date_jalali" placeholder="۱۴۰۵/۰۸/۲۶"></label>' +
        '          <label class="field"><span>ساعت</span><input type="time" data-f="due_time"></label>' +
        '        </div>' +
        '        <div class="form-row">' +
        '          <label class="field"><span>اولویت</span><select data-f="priority"><option value="low">کم</option><option value="medium">متوسط</option><option value="high">زیاد</option><option value="urgent">فوری</option></select></label>' +
        '          <label class="field"><span>مدت تخمینی (دقیقه)</span><input type="number" min="0" max="1440" data-f="estimated_minutes"></label>' +
        '        </div>' +
        '        <div class="form-row">' +
        '          <label class="field"><span>مسئول انجام</span><select data-f="assigned_to">' + users + '</select></label>' +
        '          <label class="field"><span>پروژه</span><select data-f="project_id"><option value="">بدون پروژه</option>' + projects + '</select></label>' +
        '        </div>' +
        '        <label class="field"><span>زیرتسک‌ها (هر خط یک مورد)</span><textarea data-f="subtasks" rows="2"></textarea></label>' +
        '        <label class="field"><span>توضیحات</span><textarea data-f="description" rows="2"></textarea></label>' +
        '        <div class="ai-meta"><span class="badge badge-future" data-badge="date"></span><span class="badge badge-medium" data-badge="prio"></span><span class="badge badge-todo" data-badge="assignee"></span></div>' +
        '      </div>' +
        '    </template>' +
        '  </div>' +
        '</div>';
      document.body.insertAdjacentHTML('beforeend', html);
      this.el = document.getElementById('modalVoice');
    },

    bind: function () {
      var self = this;
      var modal = this.el;
      modal.addEventListener('click', function (e) { if (e.target === modal) self.close(); });
      modal.querySelectorAll('[data-voice-close]').forEach(function (b) {
        b.addEventListener('click', function () { self.close(); self.options.onManual && self.options.onManual(); });
      });
      document.getElementById('recBtn').addEventListener('click', function () { self.toggleRecord(); });
      document.getElementById('btnVoiceProcess').addEventListener('click', function () { self.process(false); });
      document.getElementById('btnVoiceReprocess').addEventListener('click', function () { self.process(true); });
      document.getElementById('btnVoiceConfirm').addEventListener('click', function () { self.confirm(); });
      document.getElementById('btnVoiceReset').addEventListener('click', function () { self.reset(); });
      document.getElementById('voiceFileInput').addEventListener('change', function (e) { self.useUploadedFile(e.target.files[0]); });
      document.getElementById('btnTranscribeTry').addEventListener('click', function () { self.startBrowserSpeech(true); });
      var sttBtn = document.getElementById('btnBrowserStt');
      sttBtn.addEventListener('click', function () { self.transcribeAudioOnly(); });
      // 🔌 در حالت آفلاین، دکمهٔ Puter (سرویس بین‌المللی) کار نمی‌کند؛ از قبل شفاف می‌کنیم
      var allowIntl = false;
      try { allowIntl = localStorage.getItem('hp_allow_international') === '1'; } catch (e) {}
      var isOffline = !!(window.APP && window.APP.offlineMode);
      if (isOffline || !allowIntl) {
        sttBtn.title = 'این دکمه به سرویس بین‌المللی Puter نیاز دارد. برای کار بدون اینترنت، از «📝 تشخیص گفتار مرورگر» یا نوشتن متن استفاده کنید.';
        sttBtn.style.opacity = '.6';
        sttBtn.textContent = '🎧 تبدیل صدا به متن (نیازمند اینترنت)';
      }
      document.getElementById('voicePreviewList').addEventListener('click', function (e) {
        var rm = e.target.closest('[data-remove]');
        if (rm) {
          var card = rm.closest('.ai-preview-card');
          var idx = Number(card.getAttribute('data-idx'));
          self.previewTasks.splice(idx, 1);
          self.renderPreview();
        }
      });
      var autoChk = document.getElementById('voiceAutoCreate');
      if (this.options.autoCreate) autoChk.checked = true;
      document.getElementById('voiceTranscript').addEventListener('input', function () {
        self.finalTranscript = this.value;
      });
      window.addEventListener('beforeunload', function () { self.stopStream(); });
    },

    open: function (prefill) {
      this.reset();
      if (prefill && prefill.transcript) {
        document.getElementById('voiceTranscript').value = prefill.transcript;
        this.finalTranscript = prefill.transcript;
      }
      this.el.classList.add('open');
      document.body.style.overflow = 'hidden';
      setTimeout(function () { drawIdleWave(document.getElementById('waveCanvas')); }, 60);
    },

    close: function () {
      this.stopRecord(true);
      this.el.classList.remove('open');
      document.body.style.overflow = '';
    },

    reset: function () {
      this.stopRecord(true);
      this.audioBlob = null;
      if (this.audioUrl) { URL.revokeObjectURL(this.audioUrl); this.audioUrl = null; }
      document.getElementById('audioPlayWrap').classList.add('hidden');
      document.getElementById('voiceAudio').removeAttribute('src');
      document.getElementById('voiceTranscript').value = '';
      document.getElementById('voiceFileInput').value = '';
      document.getElementById('voicePreviewWrap').classList.add('hidden');
      document.getElementById('voicePreviewList').innerHTML = '';
      document.getElementById('voiceProcessing').classList.add('hidden');
      document.getElementById('voiceProvider').textContent = '—';
      document.getElementById('recTimer').textContent = '۰۰:۰۰';
      document.getElementById('recStatus').textContent = 'برای شروع ضبط، روی میکروفن بزنید.';
      document.getElementById('btnVoiceProcess').disabled = false;
      this.finalTranscript = '';
      this.interimTranscript = '';
      this.previewTasks = [];
      this.previewMeta = null;
      this.seconds = 0;
      this.lastSpeechError = '';
      this.browserSpeechGotResult = false;
      var hintReset = document.getElementById('speechHint');
      var hint = document.getElementById('speechHint');
      var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      hint.textContent = SR
        ? '🎧 تشخیص گفتار فارسی مرورگر فعال است؛ متن هم‌زمان با صحبت شما نوشته می‌شود.'
        : 'ℹ️ مرورگر شما تشخیص گفتار ندارد؛ صدا ضبط و برای پردازش به سرور ارسال می‌شود (Groq/Gemini رایگان یا موتور فارسی داخلی).';
      setTimeout(function () { drawIdleWave(document.getElementById('waveCanvas')); }, 50);
    },

    /* ---------- ضبط صدا ---------- */
    toggleRecord: function () {
      if (this.recording) this.stopRecord();
      else this.startRecord();
    },

    startRecord: function () {
      var self = this;
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        toast('مرورگر شما از ضبط صدا پشتیبانی نمی‌کند. می‌توانید فایل صوتی آپلود کنید یا متن بنویسید.', 'error');
        return;
      }
      navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }).then(function (stream) {
        self.stream = stream;
        self.chunks = [];
        var mime = '';
        ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'].forEach(function (m) {
          if (!mime && window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m)) mime = m;
        });
        try {
          self.recorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
        } catch (err) {
          toast('خطا در راه‌اندازی ضبط صدا: ' + err.message, 'error');
          self.stopStream();
          return;
        }
        self.recorder.ondataavailable = function (e) { if (e.data && e.data.size > 0) self.chunks.push(e.data); };
        self.recorder.onstop = function () { self.finishRecording(); };
        self.recorder.start(250);
        self.recording = true;
        self.seconds = 0;
        self.setRecordingUI(true);
        self.startWaveform(stream);
        self.startTimer();
        self.startBrowserSpeech(false);
        // 🎙️ تشخیص خودکار پایان گفتار (VAD) — بدون هیچ سرویس بیرونی
        self.startAutoStopVad();
      }).catch(function (err) {
        var msg = 'دسترسی به میکروفن امکان‌پذیر نیست.';
        if (err && (err.name === 'NotAllowedError' || err.name === 'SecurityError')) msg = 'اجازه دسترسی به میکروفن داده نشد. از تنظیمات مرورگر اجازه دهید (سایت باید HTTPS باشد).';
        else if (err && err.name === 'NotFoundError') msg = 'میکروفنی روی این دستگاه پیدا نشد.';
        toast(msg, 'error');
      });
    },

    /**
     * تشخیص خودکار سکوت: وقتی کاربر چند لحظه ساکت ماند، ضبط خودکار تمام می‌شود.
     * کاملاً محلی (OfflineAudio) — در ایران با اینترنت قطع هم کار می‌کند.
     */
    startAutoStopVad: function () {
      var self = this;
      this.stopVad();
      if (!window.OfflineAudio || !this.stream) return;
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      try {
        if (!this.audioCtx || this.audioCtx.state === 'closed') this.audioCtx = new AC();
      } catch (e) { return; }
      try {
        this.vad = window.OfflineAudio.createVad(this.audioCtx, this.stream, {
          silenceMs: 1800,
          threshold: 0.035,
          maxMs: 60000,
          onSpeech: function () {
            var st = document.getElementById('recStatus');
            if (st) st.textContent = '🎙️ صدا شنیده شد — صحبت کنید… (با ۲ ثانیه سکوت، خودکار پایان می‌یابد)';
          },
          onStop: function (reason) {
            if (!self.recording) return;
            var st = document.getElementById('recStatus');
            if (st) st.textContent = reason === 'maxlen'
              ? '⏱️ به سقف ۶۰ ثانیه رسید — ضبط متوقف شد.'
              : '⏸️ سکوت تشخیص داده شد — ضبط خودکار متوقف شد.';
            self.stopRecord();
          }
        });
      } catch (e) {
        this.vad = null;
      }
    },

    stopVad: function () {
      if (this.vad) { try { this.vad.stop(); } catch (e) {} this.vad = null; }
    },

    stopRecord: function (silent) {
      var self = this;
      if (this.recorder && this.recorder.state !== 'inactive') {
        try { this.recorder.stop(); } catch (e) {}
      }

      // ⚠️ نکته مهم: اگر تشخیص گفتار مرورگر را «فوراً» قطع کنیم، Chrome فرصت نمی‌کند
      // آخرین نتیجهٔ نهایی (final) را بفرستد و متن خالی می‌ماند. پس چند لحظه صبر می‌کنیم.
      if (this.speechStopTimer) { clearTimeout(this.speechStopTimer); this.speechStopTimer = null; }
      if (silent || !this.recActive) {
        this.stopBrowserSpeech();
      } else {
        // به کاربر بازخورد بده و منتظر نتیجه نهایی بمان
        var status = document.getElementById('recStatus');
        if (status) status.textContent = '⏳ در حال نهایی‌سازی متن گفتار...';
        try { if (this.recognition) this.recognition.stop(); } catch (e) {}
        this.speechStopTimer = setTimeout(function () {
          self.speechStopTimer = null;
          self.stopBrowserSpeech();
          self.finalizeTranscript();
        }, 1400);
      }

      this.stopVad();
      this.stopTimer();
      this.stopWaveform();
      this.recording = false;
      this.setRecordingUI(false);
      if (silent) this.stopStream();
    },

    /**
     * جمع‌بندی متن نهایی: هر چیزی که مرورگر تشخیص داده را در کادر متن می‌نویسد
     * تا کاربر بتواند قبل از پردازش ببیند/ویرایش کند.
     */
    finalizeTranscript: function () {
      var box = document.getElementById('voiceTranscript');
      if (!box) return;
      var text = (box.value || '').trim();
      if (text === '' && this.finalTranscript) {
        text = this.finalTranscript.trim();
        box.value = text;
      }
      var status = document.getElementById('recStatus');
      if (status) status.textContent = '✅ ضبط پایان یافت. حالا «پردازش هوشمند» را بزنید.';
      if (text !== '') {
        this.browserSpeechGotResult = true;
        var hint = document.getElementById('speechHint');
        if (hint) hint.textContent = '✅ متن گفتار نوشته شد. اگر ناقص است، آن را اصلاح کنید و سپس «پردازش هوشمند» را بزنید.';
      } else {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        var h2 = document.getElementById('speechHint');
        if (h2) {
          h2.textContent = SR
            ? 'ℹ️ مرورگر متنی برنگرداند' + (this.lastSpeechError ? ' (' + this.lastSpeechError + ')' : '') +
              '؛ می‌توانید دوباره بگویید، متن را تایپ کنید یا دکمه «تبدیل صدا به متن بدون کلید» را بزنید.'
            : 'ℹ️ این مرورگر تشخیص گفتار ندارد؛ دکمه «تبدیل صدا به متن بدون کلید» یا تایپ متن را استفاده کنید.';
        }
      }
    },

    finishRecording: function () {
      var self = this;
      if (!this.chunks.length) return;
      var type = (this.recorder && this.recorder.mimeType) || 'audio/webm';
      this.audioBlob = new Blob(this.chunks, { type: type.indexOf('mp4') >= 0 ? 'audio/mp4' : 'audio/webm' });
      if (this.audioUrl) URL.revokeObjectURL(this.audioUrl);
      this.audioUrl = URL.createObjectURL(this.audioBlob);
      var audio = document.getElementById('voiceAudio');
      audio.src = this.audioUrl;
      document.getElementById('audioPlayWrap').classList.remove('hidden');
      this.stopStream();
      document.getElementById('recStatus').textContent = '✅ ضبط پایان یافت. حالا «پردازش هوشمند» را بزنید.';
      // متن کادر را دست‌نخورده نگه می‌داریم؛ فقط اگر خالی بود از نتیجه مرورگر پر می‌کنیم
      var box = document.getElementById('voiceTranscript');
      if (box && box.value.trim() === '' && this.finalTranscript) {
        box.value = this.finalTranscript.trim();
      }
      drawIdleWave(document.getElementById('waveCanvas'));
    },

    stopStream: function () {
      if (this.stream) {
        try { this.stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
        this.stream = null;
      }
    },

    setRecordingUI: function (on) {
      var btn = document.getElementById('recBtn');
      btn.classList.toggle('recording', on);
      btn.textContent = on ? '⏹️' : '🎙️';
      document.getElementById('recStatus').textContent = on ? '🔴 در حال ضبط... برای پایان دوباره بزنید.' : document.getElementById('recStatus').textContent;
      document.getElementById('btnVoiceProcess').disabled = on;
    },

    startTimer: function () {
      var self = this;
      this.timerId = setInterval(function () {
        self.seconds++;
        var m = Math.floor(self.seconds / 60), s = self.seconds % 60;
        document.getElementById('recTimer').textContent = FAT(String(m).padStart(2, '0')) + ':' + FAT(String(s).padStart(2, '0'));
        if (self.seconds >= 180) { self.stopRecord(); toast('حداکثر زمان ضبط ۳ دقیقه است.', 'info'); }
      }, 1000);
    },
    stopTimer: function () { if (this.timerId) { clearInterval(this.timerId); this.timerId = null; } },

    /* ---------- موج صوتی ---------- */
    startWaveform: function (stream) {
      var canvas = document.getElementById('waveCanvas');
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      try {
        this.audioCtx = new AC();
        var source = this.audioCtx.createMediaStreamSource(stream);
        this.analyser = this.audioCtx.createAnalyser();
        this.analyser.fftSize = 256;
        source.connect(this.analyser);
      } catch (e) { return; }
      var ctx = canvas.getContext('2d');
      var data = new Uint8Array(this.analyser.frequencyBinCount);
      var self = this;
      var draw = function () {
        self.rafId = requestAnimationFrame(draw);
        self.analyser.getByteFrequencyData(data);
        var w = canvas.width, h = canvas.height;
        ctx.clearRect(0, 0, w, h);
        var bars = 64, bw = w / bars;
        for (var i = 0; i < bars; i++) {
          var v = data[i * 2] / 255;
          var bh = Math.max(3, v * h * 0.92);
          var hue = 172 + (v * 40);
          ctx.fillStyle = 'hsla(' + hue + ',70%,' + (42 + v * 18) + '%,' + (0.45 + v * 0.55) + ')';
          ctx.beginPath();
          var x = i * bw + 1;
          var y = (h - bh) / 2;
          var r = Math.min(3, bw / 2);
          ctx.roundRect ? ctx.roundRect(x, y, Math.max(2, bw - 2), bh, r) : ctx.rect(x, y, Math.max(2, bw - 2), bh);
          ctx.fill();
        }
      };
      draw();
    },
    stopWaveform: function () {
      if (this.rafId) { cancelAnimationFrame(this.rafId); this.rafId = null; }
      if (this.audioCtx) { try { this.audioCtx.close(); } catch (e) {} this.audioCtx = null; }
      this.analyser = null;
    },

    /* ---------- تشخیص گفتار مرورگر ---------- */
    startBrowserSpeech: function (manual) {
      var self = this;
      var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SR) {
        if (manual) toast('مرورگر شما از تشخیص گفتار پشتیبانی نمی‌کند. صدا را ضبط کنید تا سرور پردازش کند.', 'info');
        return;
      }
      if (this.recActive) return;
      try {
        var rec = new SR();
        rec.lang = 'fa-IR';
        rec.continuous = true;
        rec.interimResults = true;
        rec.maxAlternatives = 1;
        rec.onresult = function (event) {
          var interim = '';
          for (var i = event.resultIndex; i < event.results.length; i++) {
            var txt = event.results[i][0].transcript;
            if (event.results[i].isFinal) self.finalTranscript += (self.finalTranscript ? ' ' : '') + txt.trim();
            else interim += txt;
          }
          self.interimTranscript = interim;
          if ((self.finalTranscript + interim).trim() !== '') self.browserSpeechGotResult = true;
          var box = document.getElementById('voiceTranscript');
          box.value = (self.finalTranscript + ' ' + interim).trim();
        };
        rec.onerror = function (e) {
          self.lastSpeechError = e && e.error ? String(e.error) : 'unknown';
          var hint = document.getElementById('speechHint');
          if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
            if (hint) hint.textContent = '⚠️ مرورگر اجازه تشخیص گفتار نداد؛ صدا برای پردازش سروری ارسال می‌شود.';
            if (manual) toast('اجازه استفاده از میکروفن برای تشخیص گفتار داده نشد.', 'error');
          } else if (e.error === 'network') {
            if (hint) hint.textContent = '⚠️ سرویس گفتار مرورگر در دسترس نیست؛ صدا در سرور پردازش می‌شود.';
            if (manual) toast('سرویس تشخیص گفتار مرورگر در دسترس نیست؛ صدا برای پردازش سروری ارسال خواهد شد.', 'info');
          } else if (e.error === 'language-not-supported') {
            if (hint) hint.textContent = '⚠️ این مرورگر از فارسی (fa-IR) پشتیبانی نمی‌کند؛ صدا در سرور پردازش می‌شود.';
          } else if (e.error === 'no-speech') {
            if (hint) hint.textContent = '⚠️ صدایی شنیده نشد؛ دوباره واضح‌تر صحبت کنید.';
          } else if (e.error === 'audio-capture') {
            if (hint) hint.textContent = '⚠️ میکروفن در دسترس نیست یا توسط برنامه دیگری اشغال شده است.';
          } else if (hint) {
            hint.textContent = '⚠️ تشخیص گفتار مرورگر خطا داد (' + e.error + ')؛ صدا در سرور پردازش می‌شود.';
          }
          self.recActive = false;
        };
        rec.onend = function () {
          self.recActive = false;
          // فقط در حین ضبط فعال، موتور را زنده نگه دار (نه بعد از توقف کاربر)
          if (self.recording && !self.speechStopTimer) {
            try { rec.start(); } catch (e) {}
          }
        };
        rec.start();
        this.recognition = rec;
        this.recActive = true;
      } catch (e) { this.recActive = false; }
    },
    stopBrowserSpeech: function () {
      if (this.speechStopTimer) { clearTimeout(this.speechStopTimer); this.speechStopTimer = null; }
      if (this.recognition) {
        try {
          this.recognition.onend = null;
          this.recognition.onresult = null;
          this.recognition.abort ? this.recognition.abort() : this.recognition.stop();
        } catch (e) {}
        this.recognition = null;
      }
      this.recActive = false;
    },

    useUploadedFile: function (file) {
      if (!file) return;
      if (!/^audio\//.test(file.type) && !/\.(mp3|wav|ogg|m4a|webm|aac|flac)$/i.test(file.name)) {
        toast('فقط فایل صوتی مجاز است.', 'error');
        return;
      }
      if (file.size > 20 * 1024 * 1024) { toast('حجم فایل صوتی بیش از ۲۰ مگابایت است.', 'error'); return; }
      this.audioBlob = file;
      if (this.audioUrl) URL.revokeObjectURL(this.audioUrl);
      this.audioUrl = URL.createObjectURL(file);
      var audio = document.getElementById('voiceAudio');
      audio.src = this.audioUrl;
      document.getElementById('audioPlayWrap').classList.remove('hidden');
      document.getElementById('recStatus').textContent = '📁 فایل «' + file.name + '» آماده پردازش است.';
      document.querySelector('#voiceFileInput').value = '';
    },

    /* ---------- تشخیص گفتار بدون کلید در مرورگر (Puter.js) ---------- */
    /**
     * بارگذاری تنبل اسکریپت Puter.js — سرویس رایگان و بدون کلید برای تبدیل صدا به متن.
     * اگر اسکریپت قبلاً بارگذاری شده باشد، همان را برمی‌گرداند.
     */
    loadPuter: function () {
      var self = this;
      // 🔌 آفلاین‌محور: Puter یک سرویس بین‌المللی است و در ایران (با اینترنت قطع)
      // در دسترس نیست. فقط اگر کاربر صریحاً اجازه دهد تلاش می‌کنیم.
      if (this.allowInternational === undefined) {
        var pref = null;
        try { pref = localStorage.getItem('hp_allow_international'); } catch (e) {}
        this.allowInternational = pref === '1';
      }
      if (!this.allowInternational) {
        return Promise.reject(new Error('OFFLINE_MODE'));
      }
      if (window.puter && window.puter.ai && typeof window.puter.ai.speech2txt === 'function') {
        return Promise.resolve(window.puter);
      }
      if (this.puterLoading) return this.puterLoading;
      this.puterLoading = new Promise(function (resolve, reject) {
        var existing = document.querySelector('script[data-puter-sdk]');
        var finish = function () {
          if (window.puter && window.puter.ai && typeof window.puter.ai.speech2txt === 'function') resolve(window.puter);
          else reject(new Error('Puter SDK بارگذاری نشد'));
        };
        if (existing) {
          existing.addEventListener('load', finish);
          existing.addEventListener('error', function () { reject(new Error('خطا در بارگذاری Puter')); });
          if (window.puter) finish();
          return;
        }
        var sc = document.createElement('script');
        sc.src = 'https://js.puter.com/v2/';
        sc.async = true;
        sc.setAttribute('data-puter-sdk', '1');
        var timer = setTimeout(function () { reject(new Error('زمان بارگذاری Puter به پایان رسید')); }, 12000);
        sc.onload = function () { clearTimeout(timer); finish(); };
        sc.onerror = function () { clearTimeout(timer); reject(new Error('مرورگر نتوانست به سرویس Puter دسترسی پیدا کند')); };
        document.head.appendChild(sc);
      });
      this.puterLoading.catch(function () { self.puterLoading = null; });
      return this.puterLoading;
    },

    /**
     * تلاش برای تبدیل صدا به متن در مرورگر بدون هیچ کلیدی (Puter.js).
     * فقط وقتی اجرا می‌شود که سرور متنی برنگردانده باشد.
     */
    transcribeInBrowser: async function () {
      if (!this.audioBlob) throw new Error('فایل صوتی برای تشخیص گفتار وجود ندارد');
      var puter;
      try {
        puter = await this.loadPuter();
      } catch (e) {
        if (e && e.message === 'OFFLINE_MODE') {
          throw new Error('حالت آفلاین فعال است. متن را در کادر بنویسید یا «پردازش هوشمند» را بزنید — موتور فارسی داخلی روی سرور خودتان کار می‌کند و به اینترنت نیاز ندارد.');
        }
        throw e;
      }
      var result;
      try {
        result = await puter.ai.speech2txt(this.audioBlob, { model: 'whisper-1', language: 'fa' });
      } catch (e) {
        // برخی نسخه‌ها گزینه language را نمی‌پذیرند؛ بدون آن تلاش دوباره
        result = await puter.ai.speech2txt(this.audioBlob);
      }
      var text = '';
      if (typeof result === 'string') text = result;
      else if (result && typeof result.text === 'string') text = result.text;
      else if (result && typeof result.transcript === 'string') text = result.transcript;
      text = (text || '').trim();
      if (!text) throw new Error('سرویس مرورگری متنی تولید نکرد');
      return text;
    },

    /**
     * پیام راهنمای دقیق وقتی صدا ضبط شده اما متنی به دست نیامده است.
     */
    sttHelpMessage: function (serverMessage) {
      var lines = [];
      lines.push('🎧 صدای شما درست ضبط شد، اما هنوز به متن تبدیل نشد.');
      if (this.lastSpeechError) {
        lines.push('دلیل تشخیص گفتار مرورگر: ' + this.lastSpeechError);
      }
      if (serverMessage) lines.push('پاسخ سرور: ' + String(serverMessage).replace(/^🎧\s*/, '').split('\n')[0]);
      lines.push('');
      lines.push('برای ادامه یکی از این راه‌ها:');
      lines.push('۱) متن جمله را در کادر «متن فرمان صوتی» بنویسید و دوباره «پردازش هوشمند» را بزنید (فوری).');
      lines.push('۲) کلید رایگان Groq (console.groq.com) را در تنظیمات وارد کنید — دقیق‌ترین حالت فارسی.');
      lines.push('۳) اگر سرور اینترنت ندارد، از دکمه «📝 تشخیص گفتار در مرورگر» استفاده کنید.');
      return lines.join('\n');
    },

    /* ---------- پردازش سروری ---------- */
    /**
     * تبدیل صدا به متن «فقط» و نوشتن در کادر متن، بدون ساخت تسک.
     * بدون هیچ کلید API و مستقیماً در مرورگر انجام می‌شود.
     */
    transcribeAudioOnly: function () {
      var self = this;
      if (!this.audioBlob) {
        toast('ابتدا صدا را ضبط کنید یا فایل صوتی بدهید.', 'error');
        return;
      }
      this.showProcessing('🎧 در حال تبدیل صدا به متن در مرورگر (بدون کلید)...');
      this.transcribeInBrowser()
        .then(function (text) {
          self.hideProcessing();
          document.getElementById('voiceTranscript').value = text;
          self.finalTranscript = text;
          var hint = document.getElementById('speechHint');
          if (hint) hint.textContent = '✅ متن از صدا استخراج شد. حالا «پردازش هوشمند» را بزنید تا تسک ساخته شود.';
          toast('متن گفتار استخراج شد. برای ساخت تسک «پردازش هوشمند» را بزنید.', 'success');
          var ta = document.getElementById('voiceTranscript');
          ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
        })
        .catch(function (e) {
          self.hideProcessing();
          toast('تبدیل صدا در مرورگر ممکن نشد: ' + (e && e.message ? e.message : 'خطای نامشخص') +
            '\nمی‌توانید متن جمله را دستی در کادر بنویسید یا در تنظیمات کلید رایگان Groq وارد کنید.', 'error', 12000);
        });
    },

    process: function (forceText) {
      var self = this;
      var transcript = document.getElementById('voiceTranscript').value.trim();
      if (!transcript && !this.audioBlob) {
        toast('لطفاً صدا ضبط کنید، فایل صوتی بدهید یا متن فرمان را بنویسید.', 'error');
        return;
      }
      // 🔇 اگر فقط صدا داریم (متنی تایپ نشده) و ضبط کاملاً ساکت است،
      // به‌جای رفت‌وبرگشت شبکه و پیام مبهم «متنی پیدا نشد»، فوراً بگو.
      var silentCheck = (!transcript && this.audioBlob && window.OfflineAudio && window.OfflineAudio.isSilentBlob)
        ? window.OfflineAudio.isSilentBlob(this.audioBlob).catch(function () { return false; })
        : Promise.resolve(false);

      silentCheck.then(function (isSilent) {
        if (isSilent && !transcript) {
          self.hideProcessing && self.hideProcessing();
          document.getElementById('recStatus').textContent = '🔇 ضبط تقریباً ساکت بود.';
          toast('صدای ضبط‌شده تقریباً ساکت است — میکروفن را بررسی کنید و نزدیک‌تر صحبت کنید، یا متن فرمان را بنویسید.', 'error', 6000);
          return;
        }
        self.runProcess(transcript, forceText);
      });
    },

    /** ارسال واقعی به سرور (پس از بررسی سکوت) */
    runProcess: function (transcript, forceText) {
      var self = this;
      this.showProcessing('در حال پردازش صدا با هوش مصنوعی رایگان...');
      var fd = new FormData();
      fd.append('transcript', transcript);
      fd.append('voice_duration_sec', String(this.seconds || 0));
      if (this.audioBlob && !forceText) fd.append('audio', this.audioBlob, 'voice.' + ((this.audioBlob.type && this.audioBlob.type.indexOf('mp4') >= 0) ? 'm4a' : 'webm'));

      this.api('voice_preview', fd, { form: true })
        .then(function (res) {
          self.finishPreview(res, transcript);
        })
        .catch(function (err) {
          var msg = (err && err.message) ? String(err.message) : 'پردازش صوتی انجام نشد.';
          // اگر صدا داریم و علت «متنی پیدا نشد» بود، یک‌بار در مرورگر (بدون کلید) تلاش می‌کنیم
          var noTranscript = /تشخیص گفتار|تبدیل به متن|موتور تشخیص/.test(msg);
          if (!forceText && self.audioBlob && noTranscript) {
            self.showProcessing('🔎 سرور متنی پیدا نکرد؛ تلاش دوم در مرورگر (بدون کلید)...');
            return self.transcribeInBrowser().then(function (text) {
              if (!text) throw new Error('خالی');
              var fd2 = new FormData();
              fd2.append('transcript', text);
              fd2.append('voice_duration_sec', String(self.seconds || 0));
              document.getElementById('voiceTranscript').value = text;
              return self.api('voice_preview', fd2, { form: true }).then(function (res) {
                self.finishPreview(res, text, ' + تشخیص گفتار مرورگر (Puter)');
              });
            }).catch(function () {
              throw new Error(self.sttHelpMessage(msg));
            });
          }
          throw err;
        })
        .catch(function (err) {
          self.hideProcessing();
          var message = (err && err.message) ? err.message : 'پردازش صوتی انجام نشد.';
          toast(message, 'error', 12000);
          // متن کامل خطا را برای کپی/خواندن دقیق نمایش بده
          self.showTranscriptHelp(message);
        });
    },

    showProcessing: function (text) {
      var box = document.getElementById('voiceProcessing');
      if (box) {
        box.innerHTML = '<div class="spinner"></div>' + esc(text || 'در حال پردازش...');
        box.classList.remove('hidden');
      }
      document.getElementById('voicePreviewWrap').classList.add('hidden');
      var btn = document.getElementById('btnVoiceProcess');
      if (btn) btn.disabled = true;
    },

    hideProcessing: function () {
      var box = document.getElementById('voiceProcessing');
      if (box) box.classList.add('hidden');
      var btn = document.getElementById('btnVoiceProcess');
      if (btn) btn.disabled = false;
    },

    showTranscriptHelp: function (message) {
      var hint = document.getElementById('speechHint');
      if (hint) {
        hint.innerHTML = '<div class="alert alert-error small" style="margin-top:8px;white-space:pre-line;text-align:right;line-height:1.9">' + esc(message) + '</div>';
      }
      // پخش‌کننده صدا را نشان بده تا کاربر مطمئن شود ضبط سالم است
      if (this.audioUrl) document.getElementById('audioPlayWrap').classList.remove('hidden');
    },

    finishPreview: function (res, fallbackTranscript, suffix) {
      var self = this;
      this.hideProcessing();
      this.previewTasks = res.tasks || [];
      this.previewMeta = {
        transcript: res.transcript || fallbackTranscript || '',
        provider: (res.provider || '') + (suffix || ''),
        voice_file: res.voice_file || null,
        voice_duration_sec: res.voice_duration_sec || this.seconds || 0
      };
      document.getElementById('voiceTranscript').value = this.previewMeta.transcript;
      document.getElementById('voiceProvider').textContent = '🤖 ' + this.previewMeta.provider;
      var hint = document.getElementById('speechHint');
      if (hint && res.notes && res.notes.length) hint.textContent = res.notes.join('\n');
      else if (hint) hint.textContent = this.browserSpeechGotResult
        ? '🎧 تشخیص گفتار مرورگر فعال است؛ متن هم‌زمان با صحبت شما نوشته می‌شود.'
        : 'ℹ️ صدا در سرور پردازش شد. برای دقت بیشتر روی صداهای طولانی، کلید رایگان Groq را در تنظیمات وارد کنید.';
      document.getElementById('voicePreviewWrap').classList.remove('hidden');
      this.renderPreview();
      toast(res.message || 'پردازش انجام شد. پیش‌نمایش را بررسی کنید.', 'success');
      if (document.getElementById('voiceAutoCreate').checked && !res.requires_review) {
        this.confirm();
      } else if (res.requires_review) {
        toast('این فرمان مبهم است؛ لطفاً پیش‌نمایش را بررسی و تأیید کنید.', 'info');
      }
      self.lastSpeechError = '';
    },

    renderPreview: function () {
      var wrap = document.getElementById('voicePreviewList');
      var tpl = document.getElementById('voiceTaskTpl');
      wrap.innerHTML = '';
      if (!this.previewTasks.length) {
        wrap.innerHTML = '<div class="empty"><span class="big">🤔</span>تسکی برای نمایش نیست. متن را واضح‌تر بنویسید.</div>';
        return;
      }
      var self = this;
      this.previewTasks.forEach(function (t, i) {
        var node = tpl.content.firstElementChild.cloneNode(true);
        node.setAttribute('data-idx', String(i));
        node.querySelector('.tnum').textContent = FAT(i + 1);
        var f = function (name) { return node.querySelector('[data-f="' + name + '"]'); };
        f('title').value = t.title || '';
        f('due_date_jalali').value = t.due_date_jalali_fa || t.due_date_jalali || '';
        var tm = (t.due_time || '').match(/^(\d{1,2}):(\d{2})/);
        f('due_time').value = tm ? (tm[1].padStart(2, '0') + ':' + tm[2]) : '';
        f('priority').value = t.priority || 'medium';
        f('estimated_minutes').value = t.estimated_minutes || 60;
        if (t.assigned_to) f('assigned_to').value = String(t.assigned_to);
        f('project_id').value = t.project_id ? String(t.project_id) : '';
        f('subtasks').value = (t.subtasks || []).join('\n');
        f('description').value = t.description || '';
        node.querySelector('[data-badge="date"]').textContent = '📅 ' + (t.due_date_human || '—') + (t.due_time_fa ? ' — ساعت ' + t.due_time_fa : '');
        var pr = node.querySelector('[data-badge="prio"]');
        pr.className = 'badge badge-' + (t.priority || 'medium');
        pr.textContent = '⚡ اولویت ' + (t.priority_label || '');
        node.querySelector('[data-badge="assignee"]').textContent = '👤 ' + (t.assigned_name || '—');
        wrap.appendChild(node);
      });
    },

    collect: function () {
      var out = [];
      var self = this;
      document.querySelectorAll('#voicePreviewList .ai-preview-card').forEach(function (node) {
        var idx = Number(node.getAttribute('data-idx'));
        var orig = self.previewTasks[idx] || {};
        var f = function (name) { return node.querySelector('[data-f="' + name + '"]').value.trim(); };
        var dateVal = f('due_date_jalali');
        var greg = dateVal ? window.JalaliJS.parseToGregorian(dateVal) : null;
        if (dateVal && !greg) { toast('تاریخ شمسی نامعتبر است: ' + dateVal, 'error'); throw new Error('invalid date'); }
        var subs = f('subtasks').split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
        out.push({
          title: f('title'),
          description: f('description'),
          due_date: greg || '',
          due_date_jalali: greg ? window.JalaliJS.gregorianToJalaliInput(greg) : '',
          due_time: f('due_time'),
          priority: f('priority'),
          assigned_to: Number(f('assigned_to')) || 0,
          project_id: f('project_id') ? Number(f('project_id')) : null,
          estimated_minutes: Number(f('estimated_minutes')) || 0,
          category: orig.category || 'عمومی',
          tags: orig.tags || '',
          recurrence: orig.recurrence || 'none',
          subtasks: subs
        });
      });
      return out;
    },

    confirm: function () {
      var self = this;
      var tasks;
      try { tasks = this.collect(); } catch (e) { return; }
      if (!tasks.length) { toast('تسکی برای ثبت وجود ندارد.', 'error'); return; }
      var invalid = tasks.filter(function (t) { return !t.title; });
      if (invalid.length) { toast('عنوان همه تسک‌ها را وارد کنید.', 'error'); return; }
      var meta = this.previewMeta || {};
      var fd = new FormData();
      fd.append('tasks', JSON.stringify(tasks));
      fd.append('voice_file', meta.voice_file || '');
      fd.append('transcript', document.getElementById('voiceTranscript').value.trim() || meta.transcript || '');
      fd.append('provider', meta.provider || '');
      fd.append('voice_duration_sec', String(meta.voice_duration_sec || this.seconds || 0));
      var btn = document.getElementById('btnVoiceConfirm');
      btn.disabled = true;
      this.api('voice_create', fd, { form: true }).then(function (res) {
        btn.disabled = false;
        toast(res.message || 'تسک‌ها ثبت شدند.', 'success');
        self.close();
        self.options.onCreated && self.options.onCreated(res);
      }).catch(function (err) {
        btn.disabled = false;
        if (err && err.message) toast(err.message, 'error');
      });
    }
  };

  function drawIdleWave(canvas) {
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var w = canvas.width, h = canvas.height;
    ctx.clearRect(0, 0, w, h);
    ctx.strokeStyle = 'rgba(15,118,110,.35)';
    ctx.lineWidth = 2;
    ctx.beginPath();
    for (var x = 0; x <= w; x += 4) {
      var y = h / 2 + Math.sin(x / 26) * 6 + Math.sin(x / 9) * 2;
      x === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
    }
    ctx.stroke();
  }

  function toast(msg, type) {
    if (window.App && window.App.toast) window.App.toast(msg, type);
    else console.log(msg);
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  global.VoiceAI = VoiceAI;
})(window);
