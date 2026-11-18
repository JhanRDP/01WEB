(function () {
    var header = document.querySelector('.header');
    var burger = header && header.querySelector('.menu_bar a');
    if (burger) {
        burger.addEventListener('click', function (e) {
            e.preventDefault();
            if (window.innerWidth <= 1165) {
                header.classList.toggle('menu-abierto');
            }
        });
    }

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

    // Las páginas estáticas dejan el submenú PRODUCTOS vacío con
    // [data-menu-categorias]. Se completa con las categorías activas que
    // devuelve el endpoint público. Si ya vienen renderizadas desde PHP no se
    // hace ninguna petición ni se duplican enlaces.
    var listasVacias = [];
    Array.prototype.forEach.call(document.querySelectorAll('[data-menu-categorias]'), function (lista) {
        if (lista.children.length === 0) listasVacias.push(lista);
    });

    if (listasVacias.length === 0 || typeof window.fetch !== 'function') {
        return;
    }

    function ocultarSubmenu(lista) {
        var item = lista.closest ? lista.closest('.submenu') : null;
        if (item) item.style.display = 'none';
    }

    window.fetch('categorias-menu.php', { credentials: 'same-origin' })
        .then(function (respuesta) {
            if (!respuesta.ok) throw new Error('HTTP ' + respuesta.status);
            return respuesta.json();
        })
        .then(function (categorias) {
            if (!Array.isArray(categorias)) return;

            Array.prototype.forEach.call(listasVacias, function (lista) {
                if (categorias.length === 0) {
                    ocultarSubmenu(lista);
                    return;
                }

                Array.prototype.forEach.call(categorias, function (categoria) {
                    if (!categoria || !categoria.id || typeof categoria.nombre !== 'string') return;
                    var nombre = categoria.nombre.trim();
                    if (nombre === '') return;

                    var item = document.createElement('li');
                    var enlace = document.createElement('a');
                    enlace.href = 'categoria.php?id=' + encodeURIComponent(String(categoria.id));
                    enlace.textContent = nombre;
                    item.appendChild(enlace);
                    lista.appendChild(item);
                });
            });
        })
        .catch(function () {
            // Sin conexión o respuesta inválida: se evita dejar un desplegable vacío.
            Array.prototype.forEach.call(listasVacias, ocultarSubmenu);
        });
})();
