(function () {
    var header = document.querySelector('.header');
    var burger = header && header.querySelector('.menu_bar a');
    if (!burger) return;

    burger.addEventListener('click', function (e) {
        e.preventDefault();
        if (window.innerWidth <= 1165) {
            header.classList.toggle('menu-abierto');
        }
    });

    Array.prototype.forEach.call(document.querySelectorAll('.menu .submenu > a'), function (a) {
        a.addEventListener('click', function (e) {
            if (window.innerWidth > 1165) return;
            e.preventDefault();
            var ul = a.nextElementSibling;
            if (!ul || !ul.classList.contains('children')) return;
            Array.prototype.forEach.call(document.querySelectorAll('.menu .children'), function (o) {
                if (o !== ul) o.style.display = 'none';
            });
            ul.style.display = (ul.style.display === 'block') ? 'none' : 'block';
        });
    });
})();
