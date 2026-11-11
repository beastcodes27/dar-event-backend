(() => {
  const panel = document.querySelector('[data-payment-check]');
  if (!panel) return;
  const notice = document.querySelector('[data-payment-progress]');
  let attempts = 0, failures = 0;
  const check = async () => {
    if (document.hidden) { setTimeout(check, 8000); return; }
    if (++attempts > 20) { notice.textContent = 'Still waiting. You can check the payment status below.'; return; }
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 30000);
    try {
      const response = await fetch(panel.dataset.paymentCheck, { method: 'POST', credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
      if (response.status === 422 || response.status === 403 || response.status === 401) { notice.textContent = 'We could not verify this payment. Use the status button or contact DarEvent support.'; return; }
      if (!response.ok) throw new Error('Status unavailable');
      const data = await response.json(); failures = 0;
      if (data.status === 'paid' || data.status === 'failed') { window.location.reload(); return; }
      notice.textContent = 'Waiting for mobile-money approval…';
    } catch {
      if (++failures >= 3) { notice.textContent = 'Payment status is temporarily unavailable. Check again below.'; return; }
    } finally { clearTimeout(timeout); }
    setTimeout(check, 8000);
  };
  setTimeout(check, 5000);
})();
