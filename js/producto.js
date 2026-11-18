(function () {
    'use strict';

    // Galería de la ficha de producto. Solo se activa cuando hay más de una
    // imagen válida; con cero o una imagen no se muestran flechas.
    var galeria = document.querySelector('[data-galeria]');
    if (!galeria) {
        return;
    }

    var imagen = galeria.querySelector('[data-galeria-img]');
    var anterior = galeria.querySelector('[data-galeria-prev]');
    var siguiente = galeria.querySelector('[data-galeria-next]');
    if (!imagen || !anterior || !siguiente) {
        return;
    }

    var imagenes;
    try {
        imagenes = JSON.parse(galeria.getAttribute('data-imagenes') || '[]');
    } catch (error) {
        return;
    }

    if (!Array.isArray(imagenes) || imagenes.length < 2) {
        return;
    }

    // Las flechas permanecen ocultas si JavaScript no está disponible.
    galeria.classList.add('tiene-galeria');

    var indice = 0;
    var altBase = imagen.getAttribute('alt') || '';

    function mostrar(nuevoIndice) {
        indice = (nuevoIndice + imagenes.length) % imagenes.length;
        imagen.src = imagenes[indice];
        imagen.alt = altBase + ' (imagen ' + (indice + 1) + ' de ' + imagenes.length + ')';
    }

    anterior.addEventListener('click', function () {
        mostrar(indice - 1);
    });

    siguiente.addEventListener('click', function () {
        mostrar(indice + 1);
    });

    galeria.addEventListener('keydown', function (evento) {
        if (evento.key === 'ArrowLeft') {
            mostrar(indice - 1);
        } else if (evento.key === 'ArrowRight') {
            mostrar(indice + 1);
        }
    });
})();
