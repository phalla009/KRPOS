document.querySelectorAll("select.js-select").forEach((select) => {
    const wrap = document.createElement("div");
    wrap.className = "cs";
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add("cs-native");

    const input = document.createElement("input");
    input.type = "text";
    input.className = "cs-input";
    input.autocomplete = "off";
    input.setAttribute("role", "combobox");

    const list = document.createElement("ul");
    list.className = "cs-list";
    list.setAttribute("role", "listbox");

    const empty = document.createElement("li");
    empty.className = "cs-empty";
    empty.textContent = "Not Found";

    const placeholderOpt = Array.from(select.options).find(
        (o) => o.value === "",
    );
    input.placeholder = placeholderOpt ? placeholderOpt.text : "";

    Array.from(select.options).forEach((opt) => {
        const li = document.createElement("li");
        li.textContent = opt.text;
        li.dataset.value = opt.value;
        if (opt.value === "") li.classList.add("placeholder");
        li.addEventListener("click", () => {
            select.value = opt.value;
            select.dispatchEvent(new Event("change", { bubbles: true }));
            close();
        });
        list.appendChild(li);
    });
    list.appendChild(empty);

    wrap.append(input, list);

    const items = () => Array.from(list.querySelectorAll("li:not(.cs-empty)"));
    const visible = () =>
        items().filter((li) => !li.classList.contains("hidden"));

    // បង្ហាញតម្លៃដែលបានជ្រើសក្នុង input
    function sync() {
        const o = select.options[select.selectedIndex];
        input.value = o && o.value !== "" ? o.text : "";
        items().forEach((li) =>
            li.classList.toggle(
                "selected",
                li.dataset.value === select.value && select.value !== "",
            ),
        );
    }

    function filter(q) {
        q = q.trim().toLowerCase();
        items().forEach((li) => {
            const match = !q || li.textContent.toLowerCase().includes(q);
            li.classList.toggle("hidden", !match);
            li.classList.remove("active");
        });
        empty.style.display = visible().length ? "none" : "block";
    }

    function open() {
        if (wrap.classList.contains("open")) return;
        wrap.classList.add("open");
        filter(""); // បង្ហាញជម្រើសទាំងអស់ជាមុន
        input.select(); // វាយភ្លាមគឺជំនួសអក្សរចាស់
        list.querySelector(".selected")?.scrollIntoView({ block: "nearest" });
    }

    function close() {
        wrap.classList.remove("open");
        sync(); // ប្រសិនបើមិនបានជ្រើស ត្រឡប់ទៅតម្លៃចាស់
    }

    sync();

    input.addEventListener("focus", open);
    input.addEventListener("click", open);
    input.addEventListener("input", () => {
        if (!wrap.classList.contains("open")) wrap.classList.add("open");
        filter(input.value);
    });

    input.addEventListener("keydown", (e) => {
        const v = visible();
        let i = v.findIndex((li) => li.classList.contains("active"));
        if (e.key === "ArrowDown" || e.key === "ArrowUp") {
            e.preventDefault();
            open();
            if (!v.length) return;
            if (i >= 0) v[i].classList.remove("active");
            i =
                e.key === "ArrowDown"
                    ? (i + 1) % v.length
                    : (i - 1 + v.length) % v.length;
            v[i].classList.add("active");
            v[i].scrollIntoView({ block: "nearest" });
        } else if (e.key === "Enter") {
            if (!wrap.classList.contains("open")) return;
            e.preventDefault();
            (v[i] || v[0])?.click();
        } else if (e.key === "Escape") {
            close();
        } else if (e.key === "Tab") {
            close();
        }
    });

    document.addEventListener("click", (e) => {
        if (!wrap.contains(e.target)) close();
    });

    select.form?.addEventListener("reset", () => setTimeout(sync));
});
