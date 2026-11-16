(() => {
    const numberedPattern = /(?:^|\/)[^/]*\d+\.png$/i;
    const images = [...document.querySelectorAll('img')].filter((img) => {
        const src = (img.getAttribute('src') || img.src || '').toLowerCase();
        return numberedPattern.test(src);
    });

    if (!images.length) {
        return;
    }

    const modal = document.createElement('div');
    modal.className = 'img-zoom-modal';
    modal.innerHTML = `
        <div class="img-zoom-content">
            <button type="button" class="btn btn-light btn-sm img-zoom-close" aria-label="Cerrar">Cerrar</button>
            <img src="" alt="Imagen ampliada">
        </div>
    `;
    document.body.appendChild(modal);

    const modalImage = modal.querySelector('img');
    const closeButton = modal.querySelector('.img-zoom-close');

    function openZoom(src, alt) {
        modalImage.src = src;
        modalImage.alt = alt;
        modal.classList.add('is-open');
    }

    function closeZoom() {
        modal.classList.remove('is-open');
    }

    images.forEach((img) => {
        if (img.closest('.img-zoom-item')) {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'img-zoom-item';

        img.classList.add('img-zoom-image');

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-warning btn-sm img-zoom-btn';
        button.textContent = 'Ver imagen';

        const parent = img.parentNode;
        if (parent) {
            parent.insertBefore(wrapper, img);
        }

        wrapper.appendChild(img);
        wrapper.appendChild(button);

        button.addEventListener('click', () => openZoom(img.src, img.alt || 'Imagen ampliada'));
    });

    closeButton.addEventListener('click', closeZoom);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeZoom();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeZoom();
        }
    });
})();
