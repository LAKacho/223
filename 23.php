// video.js — "тихий" фотоснимок перед тестом без блокировок стрима/записи
// Работает так:
// 1) Поднимает (или переиспользует) общий stream в <video id="video">.
// 2) Через 200–400 мс делает снимок кадра (ImageCapture или canvas) и отправляет в photoSave.php.
// 3) В любом случае (успех/ошибка/таймаут) — кидает событие и, если есть, вызывает startAfterPhoto() из zetta32.js.
// Никаких UI-оверлеев и ожиданий face-api — тест не блокируется.

(function () {
  'use strict';

  // --- Настройки ---
  const FIRST_FRAME_DELAY_MS = 300;       // даём камере "проснуться"
  const VIDEO_READY_TIMEOUT_MS = 1500;    // максимум ждём готовности кадра
  const FACEAPI_TIMEOUT_MS = 1500;        // пробуем подгрузить face-api, но не ждём дольше
  const UPLOAD_URL = 'photoSave.php';     // серверный приёмник фото (cookie id обязателен на сервере)

  // --- Гард от двойного запуска ---
  if (window.__facePhotoKick) return;
  window.__facePhotoKick = true;

  // --- Утилиты ---
  function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
  function dispatch(name, detail) {
    try { document.dispatchEvent(new CustomEvent(name, { detail })); } catch {}
  }

  function getVideoEl() {
    return document.getElementById('video') || document.querySelector('video');
  }

  async function ensureStream() {
    // 1) Уже есть общий стрим?
    if (window.__camStream) return window.__camStream;

    // 2) Уже есть превью в <video>?
    const v = getVideoEl();
    if (v && v.srcObject && v.srcObject.getTracks && v.srcObject.getTracks().length) {
      window.__camStream = v.srcObject;
      return window.__camStream;
    }

    // 3) Берём новый общий стрим (видео+аудио — чтобы потом zetta32 мог писать сразу)
    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });

    if (v) {
      v.srcObject = stream;
      try { await v.play(); } catch {}
    }
    window.__camStream = stream;
    return stream;
  }

  async function waitVideoReady(maxWaitMs) {
    const v = getVideoEl();
    const start = Date.now();
    while (Date.now() - start < maxWaitMs) {
      if (v && v.videoWidth > 0 && v.videoHeight > 0) return true;
      await sleep(50);
    }
    return false;
  }

  function grabFrameFromStream(stream) {
    // Пытаемся через ImageCapture, иначе — через canvas с <video>.
    return new Promise(async (resolve, reject) => {
      const v = getVideoEl();
      const track = stream && stream.getVideoTracks ? stream.getVideoTracks()[0] : null;

      async function viaCanvas() {
        try {
          if (!v || !v.videoWidth) throw new Error('video_not_ready');
          const c = document.createElement('canvas');
          c.width = v.videoWidth;
          c.height = v.videoHeight;
          const ctx = c.getContext('2d');
          ctx.drawImage(v, 0, 0);
          c.toBlob(b => b ? resolve(b) : reject(new Error('toBlob_failed')), 'image/jpeg', 0.85);
        } catch (e) { reject(e); }
      }

      try {
        if ('ImageCapture' in window && track) {
          const ic = new ImageCapture(track);
          ic.grabFrame().then((bmp) => {
            const c = document.createElement('canvas');
            c.width = bmp.width;
            c.height = bmp.height;
            const ctx = c.getContext('2d');
            ctx.drawImage(bmp, 0, 0);
            c.toBlob(b => b ? resolve(b) : viaCanvas(), 'image/jpeg', 0.85);
          }).catch(viaCanvas);
        } else {
          viaCanvas();
        }
      } catch {
        viaCanvas();
      }
    });
  }

  // Опционально: быстрая подгрузка face-api без блокировки (для совместимости со старым пайплайном)
  function loadScriptWithTimeout(src, timeoutMs) {
    return new Promise((resolve) => {
      let done = false;
      const s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = () => { if (!done) { done = true; resolve(true); } };
      s.onerror = () => { if (!done) { done = true; resolve(false); } };
      document.head.appendChild(s);
      setTimeout(() => { if (!done) { done = true; resolve(false); } }, timeoutMs);
    });
  }

  async function maybeLoadFaceApi() {
    try {
      if (window.faceapi && faceapi.nets && faceapi.nets.tinyFaceDetector && faceapi.nets.tinyFaceDetector.params) {
        return true;
      }
      const ok = await loadScriptWithTimeout('/camera1/face-api.min.js', FACEAPI_TIMEOUT_MS);
      if (!ok || !window.faceapi) return false;
      // Загружаем модели, но не ждём бесконечно
      const p = faceapi.nets.tinyFaceDetector.loadFromUri('/camera1/models/');
      // не дожидаемся завершения загрузки — пусть грузится фоном
      Promise.race([p, sleep(FACEAPI_TIMEOUT_MS)]).catch(() => {});
      return true;
    } catch { return false; }
  }

  async function uploadPhoto(blob) {
    const fd = new FormData();
    fd.append('photo', blob, 'face.jpg'); // cookie id на сервере обязателен
    const resp = await fetch(UPLOAD_URL, { method: 'POST', body: fd });
    const text = (await resp.text()).trim().toLowerCase();
    return text === 'success';
  }

  async function takePhotoSilently() {
    try {
      // 1) Гарантируем единый стрим и превью
      const stream = await ensureStream();

      // 2) Даём камере собрать первый кадр
      await sleep(FIRST_FRAME_DELAY_MS);
      await waitVideoReady(VIDEO_READY_TIMEOUT_MS);

      // 3) Пробуем подгрузить face-api, но не блокируемся
      maybeLoadFaceApi(); // без await — не мешаем тесту и записи

      // 4) Берём кадр
      const blob = await grabFrameFromStream(stream);

      // 5) Отправляем на сервер
      const ok = await uploadPhoto(blob);

      if (ok) {
        dispatch('face-photo-saved', { server: 'success' });
      } else {
        dispatch('face-photo-failed', { server: 'unexpected_response' });
      }
    } catch (e) {
      // Любая ошибка — не блокируем: просто сигналим, что фото не удалось
      dispatch('face-photo-failed', { reason: 'exception', error: String(e) });
    } finally {
      // 6) В любом случае — даём системе продолжить запись/тест
      if (typeof window.startAfterPhoto === 'function') {
        try { window.startAfterPhoto(); } catch {}
      }
      dispatch('face-photo-done');
    }
  }

  // Стартуем при загрузке документа
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', takePhotoSilently, { once: true });
  } else {
    takePhotoSilently();
  }

})();