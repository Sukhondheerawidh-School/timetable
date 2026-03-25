/* global Swal */
(function (global) {
  'use strict';

  function hasSwal() {
    const t = typeof global.Swal;
    return (t === 'function' || t === 'object') && typeof global.Swal.fire === 'function';
  }

  function fallbackAlert(message) {
    global.alert(String(message ?? ''));
  }

  function fallbackConfirm(message) {
    return global.confirm(String(message ?? 'ยืนยัน?'));
  }

  function normalizeText(v) {
    if (v === null || v === undefined) return '';
    return String(v);
  }

  global.ttAlert = function ttAlert(opts) {
    // opts: { title, text, icon, html }
    const title = normalizeText(opts?.title || '');
    const text = normalizeText(opts?.text || '');
    const icon = opts?.icon || 'info';
    const html = opts?.html;

    if (!hasSwal()) {
      fallbackAlert(text || title);
      return Promise.resolve();
    }

    const fireOpts = {
      icon,
      title: title || undefined,
      text: html ? undefined : (text || undefined),
      html: html || undefined,
      confirmButtonText: 'ตกลง',
    };

    return global.Swal.fire(fireOpts);
  };

  global.ttConfirm = async function ttConfirm(opts) {
    // opts: { title, text, icon, confirmButtonText, cancelButtonText }
    const title = normalizeText(opts?.title || 'ยืนยันการทำรายการ');
    const text = normalizeText(opts?.text || 'คุณแน่ใจหรือไม่?');
    const icon = opts?.icon || 'warning';
    const confirmButtonText = opts?.confirmButtonText || 'ยืนยัน';
    const cancelButtonText = opts?.cancelButtonText || 'ยกเลิก';

    if (!hasSwal()) {
      return fallbackConfirm(text || title);
    }

    const res = await global.Swal.fire({
      icon,
      title,
      text,
      showCancelButton: true,
      confirmButtonText,
      cancelButtonText,
      reverseButtons: true,
      focusCancel: true,
    });

    return !!res.isConfirmed;
  };

  global.ttConfirmSubmit = function ttConfirmSubmit(form, opts) {
    const title = normalizeText(opts?.title || 'ยืนยันการลบ');
    const text = normalizeText(opts?.text || 'ยืนยันลบรายการนี้?');
    const icon = opts?.icon || 'warning';
    const confirmButtonText = opts?.confirmButtonText || 'ลบ';
    const cancelButtonText = opts?.cancelButtonText || 'ยกเลิก';

    if (!form || typeof form.submit !== 'function') return true;

    if (!hasSwal()) {
      return fallbackConfirm(text || title);
    }

    global.Swal.fire({
      icon,
      title,
      text,
      showCancelButton: true,
      confirmButtonText,
      cancelButtonText,
      reverseButtons: true,
      focusCancel: true,
    }).then((res) => {
      if (res.isConfirmed) form.submit();
    });

    return false;
  };

  global.ttConfirmLink = function ttConfirmLink(anchor, opts) {
    const title = normalizeText(opts?.title || 'ยืนยันการทำรายการ');
    const text = normalizeText(opts?.text || 'คุณแน่ใจหรือไม่?');
    const icon = opts?.icon || 'warning';
    const confirmButtonText = opts?.confirmButtonText || 'ยืนยัน';
    const cancelButtonText = opts?.cancelButtonText || 'ยกเลิก';

    if (!anchor || !anchor.href) return true;

    if (!hasSwal()) {
      return fallbackConfirm(text || title);
    }

    global.Swal.fire({
      icon,
      title,
      text,
      showCancelButton: true,
      confirmButtonText,
      cancelButtonText,
      reverseButtons: true,
      focusCancel: true,
    }).then((res) => {
      if (res.isConfirmed) global.location.href = anchor.href;
    });

    return false;
  };

  global.ttDoubleConfirmSubmit = function ttDoubleConfirmSubmit(form, opts1, opts2) {
    if (!form || typeof form.submit !== 'function') return true;

    if (!hasSwal()) {
      if (!fallbackConfirm(normalizeText(opts1?.text || 'ยืนยัน?'))) return false;
      return fallbackConfirm(normalizeText(opts2?.text || 'ยืนยันอีกครั้ง?'));
    }

    global.Swal.fire({
      icon: opts1?.icon || 'warning',
      title: normalizeText(opts1?.title || 'ยืนยันการทำรายการ'),
      text: normalizeText(opts1?.text || 'คุณแน่ใจหรือไม่?'),
      showCancelButton: true,
      confirmButtonText: opts1?.confirmButtonText || 'ดำเนินการต่อ',
      cancelButtonText: opts1?.cancelButtonText || 'ยกเลิก',
      reverseButtons: true,
      focusCancel: true,
    }).then((res1) => {
      if (!res1.isConfirmed) return;

      global.Swal.fire({
        icon: opts2?.icon || 'warning',
        title: normalizeText(opts2?.title || 'ยืนยันอีกครั้ง'),
        text: normalizeText(opts2?.text || 'การกระทำนี้ไม่สามารถกู้คืนได้!'),
        showCancelButton: true,
        confirmButtonText: opts2?.confirmButtonText || 'ยืนยัน',
        cancelButtonText: opts2?.cancelButtonText || 'ยกเลิก',
        reverseButtons: true,
        focusCancel: true,
      }).then((res2) => {
        if (res2.isConfirmed) form.submit();
      });
    });

    return false;
  };
})(window);
