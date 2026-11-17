/* ============================================================================
 * همکار پلاس — لایهٔ صوتی آفلاین
 * ----------------------------------------------------------------------------
 * این فایل هیچ ارتباط شبکه‌ای ندارد. تمام محاسبات (تشخیص گفتار/سکوت،
 * اندازه‌گیری انرژی، برش صدا) در خود مرورگر انجام می‌شود.
 * هدف: کارکردن کامل در ایران بدون اینترنت بین‌الملل.
 * ========================================================================== */
(function (G) {
  'use strict';

  var OfflineAudio = {
    /* ---------- تشخیص خودکار شروع/پایان گفتار (VAD ساده و سبک) ---------- */

    /**
     * ساخت یک آشکارساز سکوت.
     * @param {AudioContext} ctx
     * @param {MediaStream} stream
     * @param {Object} opts
     *   - silenceMs  : چند میلی‌ثانیه سکوت = پایان گفتار (پیش‌فرض ۱۸۰۰)
     *   - threshold  : آستانهٔ انرژی ۰..۱ (پیش‌فرض ۰٫۰۳۵)
     *   - maxMs      : سقف طول ضبط (پیش‌فرض ۶۰ ثانیه)
     *   - onSpeech   : وقتی گفتار شروع شد
     *   - onLevel    : هر فریم با مقدار ۰..۱
     *   - onStop     : وقتی سکوت طولانی شد (برای توقف ضبط)
     */
    createVad: function (ctx, stream, opts) {
      opts = opts || {};
      // ورودی نامعتبر → VAD بی‌اثر، نه استثنا (فراخوان‌کننده باید بدون try/catch امن باشد)
      if (!ctx || typeof ctx.createMediaStreamSource !== 'function' || !stream) {
        return { stop: function () {}, getLevel: function () { return 0; }, hasSpeech: function () { return false; } };
      }
      var silenceMs = opts.silenceMs || 1800;
      var threshold = opts.threshold || 0.035;
      var maxMs = opts.maxMs || 60000;

      var source = ctx.createMediaStreamSource(stream);
      var analyser = ctx.createAnalyser();
      analyser.fftSize = 512;
      analyser.smoothingTimeConstant = 0.6;
      source.connect(analyser);

      var buf = new Uint8Array(analyser.frequencyBinCount);
      var started = false;
      var lastVoiceAt = 0;
      var beginAt = Date.now();
      var raf = null;
      var stopped = false;

      function level() {
        analyser.getByteFrequencyData(buf);
        var sum = 0;
        // فقط باندهای گفتار انسان (تقریباً ۳۰۰–۳۴۰۰ هرتز) را می‌سنجیم
        var nyquist = (ctx.sampleRate || 48000) / 2;
        var lo = Math.max(1, Math.floor(300 / nyquist * buf.length));
        var hi = Math.min(buf.length - 1, Math.ceil(3400 / nyquist * buf.length));
        var n = 0;
        for (var i = lo; i <= hi; i++) { sum += buf[i]; n++; }
        if (!n) return 0;
        return (sum / n) / 255;
      }

      function tick() {
        if (stopped) return;
        raf = requestAnimationFrame(tick);
        var v = level();
        if (opts.onLevel) { try { opts.onLevel(v); } catch (e) {} }

        var now = Date.now();
        if (v >= threshold) {
          lastVoiceAt = now;
          if (!started) {
            started = true;
            if (opts.onSpeech) { try { opts.onSpeech(); } catch (e) {} }
          }
        }

        // پایان بر اساس سکوت
        if (started && now - lastVoiceAt >= silenceMs) {
          stop();
          if (opts.onStop) { try { opts.onStop('silence'); } catch (e) {} }
          return;
        }
        // سقف طول
        if (now - beginAt >= maxMs) {
          stop();
          if (opts.onStop) { try { opts.onStop('maxlen'); } catch (e) {} }
        }
      }

      function stop() {
        if (stopped) return;
        stopped = true;
        if (raf) { cancelAnimationFrame(raf); raf = null; }
        try { source.disconnect(); } catch (e) {}
        try { analyser.disconnect(); } catch (e) {}
      }

      raf = requestAnimationFrame(tick);

      return {
        stop: stop,
        getLevel: level,
        hasSpeech: function () { return started; }
      };
    },

    /* ---------- اندازه‌گیری کیفیت ضبط (بدون شبکه) ---------- */

    /**
     * آیا این بلاب صوتی «بی‌صدا» است؟ (کاربر دکمه را زده ولی چیزی نگفته)
     * با رمزگشایی محلی و محاسبهٔ RMS انجام می‌شود.
     */
    isSilentBlob: function (blob, threshold) {
      threshold = threshold || 0.012;
      var AC = G.AudioContext || G.webkitAudioContext;
      if (!AC || !blob || !blob.size) {
        return Promise.resolve(false);
      }
      return new Promise(function (resolve) {
        var reader = new FileReader();
        reader.onload = function () {
          var ctx;
          try { ctx = new AC(); } catch (e) { return resolve(false); }
          ctx.decodeAudioData(
            reader.result,
            function (audioBuf) {
              try {
                var ch = audioBuf.getChannelData(0);
                var sum = 0;
                // نمونه‌برداری تُنُک برای سرعت
                var step = Math.max(1, Math.floor(ch.length / 20000));
                var n = 0;
                for (var i = 0; i < ch.length; i += step) { sum += ch[i] * ch[i]; n++; }
                var rms = n ? Math.sqrt(sum / n) : 0;
                ctx.close();
                resolve(rms < threshold);
              } catch (e) { try { ctx.close(); } catch (e2) {} resolve(false); }
            },
            function () { try { ctx.close(); } catch (e) {} resolve(false); }
          );
        };
        reader.onerror = function () { resolve(false); };
        reader.readAsArrayBuffer(blob);
      });
    },

    /**
     * مدت واقعی صدا (ثانیه) — بدون شبکه، برای نمایش به کاربر
     */
    durationOf: function (blob) {
      var AC = G.AudioContext || G.webkitAudioContext;
      if (!AC || !blob || !blob.size) return Promise.resolve(0);
      return new Promise(function (resolve) {
        var reader = new FileReader();
        reader.onload = function () {
          var ctx;
          try { ctx = new AC(); } catch (e) { return resolve(0); }
          ctx.decodeAudioData(
            reader.result,
            function (b) { var d = b.duration || 0; try { ctx.close(); } catch (e) {} resolve(d); },
            function () { try { ctx.close(); } catch (e) {} resolve(0); }
          );
        };
        reader.onerror = function () { resolve(0); };
        reader.readAsArrayBuffer(blob);
      });
    },

    /**
     * آیا مرورگر «تشخیص گفتار محلی» دارد؟ (Web Speech API)
     * نکته: در کروم روی اندروید/ویندوز معمولاً آفلاین کار می‌کند،
     * در فایرفاکس نیاز به تنظیم دستی دارد.
     */
    hasBrowserStt: function () {
      return !!(G.SpeechRecognition || G.webkitSpeechRecognition);
    },

    /**
     * آیا موتور صوتی مرورگر آماده است؟
     */
    hasAudioCapture: function () {
      return !!(G.navigator && G.navigator.mediaDevices && G.navigator.mediaDevices.getUserMedia)
        && !!(G.MediaRecorder);
    }
  };

  G.OfflineAudio = OfflineAudio;
})(window);
