  // Preloader Logic
        document.addEventListener('DOMContentLoaded', () => {
            const preloader = document.getElementById('preloader');
            const mainContent = document.getElementById('main-content');

            // Minimum display time for branding impact
            const minDisplayTime = 3000;
            const startTime = Date.now();

            window.addEventListener('load', () => {
                const elapsed = Date.now() - startTime;
                const remaining = Math.max(0, minDisplayTime - elapsed);

                setTimeout(() => {
                    preloader.classList.add('fade-out');
                    mainContent.classList.add('visible');

                    // Remove preloader from DOM after transition
                    setTimeout(() => {
                        preloader.remove();
                    }, 600);
                }, remaining);
            });
        });