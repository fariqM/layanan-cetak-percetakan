<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm mb-4" style="border-bottom: 3px solid #3b82f6;">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="{{ url('/') }}" style="color: #0f172a;">
            <span class="fs-4 me-2">🎓</span> ID Card UINSA
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center fw-semibold">
                <li class="nav-item me-2">
                    <a class="nav-link {{ request()->is('/') ? 'active text-primary fw-bold' : 'text-secondary' }}" href="{{ url('/') }}">
                        🏠 Dashboard
                    </a>
                </li>
                <li class="nav-item me-2">
                    <a class="nav-link {{ request()->is('foto') ? 'active text-primary fw-bold' : 'text-secondary' }}" href="{{ url('/foto') }}">
                        📸 Panel Foto
                    </a>
                </li>
                <li class="nav-item me-3">
                    <a class="nav-link {{ request()->is('foto/sinkronisasi') ? 'active text-primary fw-bold' : 'text-secondary' }}" href="{{ url('/foto/sinkronisasi') }}">
                        🔄 Sinkronisasi SFTP
                    </a>
                </li>
                <li class="nav-item border-start ps-3 my-2 my-lg-0">
                    <a class="btn btn-outline-dark btn-sm rounded-pill px-3 fw-bold" href="/cetak">
                        🖨️ Layanan Cetak
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>