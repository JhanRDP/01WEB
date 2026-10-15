(function () {
    var btn = document.querySelector('.fab-wsp');
    var pop = document.getElementById('wsp-pop');
    if (!btn || !pop) return;
    var timer = null;

    function open() {
        clearTimeout(timer);
        pop.classList.add('is-visible');
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                pop.classList.add('is-open');
            });
        });
        btn.setAttribute('aria-expanded', 'true');
    }

    function close() {
        clearTimeout(timer);
        pop.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        timer = setTimeout(function () {
            pop.classList.remove('is-visible');
        }, 300);
    }

    btn.addEventListener('click', function (e) {
        e.preventDefault();
        if (pop.classList.contains('is-open')) {
            close();
        } else {
            open();
        }
    });

    var x = pop.querySelector('.wsp-pop-close');
    if (x) x.addEventListener('click', close);
})();
