(function () {
  function markPrimaryButtons() {
    var submitButtons = document.querySelectorAll('button[type="submit"], .bg-cyan-600, .bg-gradient-to-r');
    submitButtons.forEach(function (button) {
      button.classList.add('portal-btn');
    });
  }

  function markQuantumToggles() {
    var candidates = document.querySelectorAll('button[id*="password" i], button[class*="password" i], .password-toggle, #togglePassword');
    candidates.forEach(function (el) {
      el.classList.add('quantum-toggle');
    });
  }

  function markSuccessStates() {
    var candidates = document.querySelectorAll('.bg-emerald-500\\/10, .text-emerald-100, .text-emerald-200, .text-green-400');
    candidates.forEach(function (el) {
      el.classList.add('success-portal');
    });
  }

  function injectChromeLogo() {
    if (document.querySelector('.chrome-logo')) {
      return;
    }
    var logo = document.createElement('div');
    logo.className = 'chrome-logo';
    logo.setAttribute('aria-hidden', 'true');
    document.body.appendChild(logo);
  }

  function pulseGlitchOnce() {
    var delayMs = 1500 + Math.floor(Math.random() * 1800);
    window.setTimeout(function () {
      document.body.classList.add('matrix-glitch');
      window.setTimeout(function () {
        document.body.classList.remove('matrix-glitch');
      }, 180);
    }, delayMs);
  }

  function markNeonText() {
    var headers = document.querySelectorAll('h1, h2');
    headers.forEach(function (header) {
      header.classList.add('y2k-neon');
    });
  }

  function init() {
    document.body.classList.add('y2k-global');
    markNeonText();
    markPrimaryButtons();
    markQuantumToggles();
    markSuccessStates();
    injectChromeLogo();
    pulseGlitchOnce();
  }

  window.NovaY2K = {
    init: init
  };
})();
