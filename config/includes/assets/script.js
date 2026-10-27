(() => {
    document.querySelectorAll("form[data-confirm]").forEach((form) => {
        form.addEventListener("submit", (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll("form[data-loading-label]").forEach((form) => {
        form.addEventListener("submit", () => {
            const submitButton = form.querySelector('button[type="submit"]');

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = form.dataset.loadingLabel;
            }
        });
    });

    const markAllButton = document.querySelector("#mark-all-present");

    if (markAllButton) {
        markAllButton.addEventListener("click", () => {
            document.querySelectorAll('input[type="radio"][value="Present"]')
                .forEach((input) => {
                    input.checked = true;
                });
        });
    }

    const period = document.querySelector("#period");

    if (period) {
        period.addEventListener("change", () => {
            const from = document.querySelector("#date_from");
            const to = document.querySelector("#date_to");
            const today = new Date();
            const localDate = (date) => {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, "0");
                const day = String(date.getDate()).padStart(2, "0");
                return `${year}-${month}-${day}`;
            };

            if (period.value === "daily") {
                from.value = localDate(today);
                to.value = localDate(today);
            } else if (period.value === "weekly") {
                const start = new Date(today);
                start.setDate(start.getDate() - 6);
                from.value = localDate(start);
                to.value = localDate(today);
            } else if (period.value === "monthly") {
                from.value = localDate(new Date(today.getFullYear(), today.getMonth(), 1));
                to.value = localDate(today);
            }
        });
    }

    document.querySelectorAll("[data-count]").forEach((counter) => {
        const target = Number(counter.dataset.count);

        if (!Number.isSafeInteger(target) || target < 0) {
            return;
        }

        if (window.matchMedia("(prefers-reduced-motion: reduce)").matches || target === 0) {
            counter.textContent = target.toLocaleString();
            return;
        }

        const duration = 700;
        let startTime;

        const updateCount = (timestamp) => {
            if (startTime === undefined) {
                startTime = timestamp;
            }

            const progress = Math.min((timestamp - startTime) / duration, 1);
            const easedProgress = 1 - Math.pow(1 - progress, 3);

            counter.textContent = Math.floor(target * easedProgress).toLocaleString();

            if (progress < 1) {
                window.requestAnimationFrame(updateCount);
            }
        };

        window.requestAnimationFrame(updateCount);
    });
})();
