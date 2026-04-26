# 🖥️ Mapa Operativo - Optiplex Server

## 📌 Info General
- **OS:** Debian/Ubuntu (Optiplex Home Server).
- **Timezone:** America/Montevideo (GMT-3).
- **Acceso:** Tailscale (100.80.170.36) y Cloudflare Tunnels.

## 📁 Arquitectura Ritual
- **Path:** /home/opti/proyectos/ritual/
- **Stack:** Docker Compose (web_publica, web_admin).
- **Persistencia:** Archivos JSON en 'compartido/data'.
- **Panel:** Launcher personalizado en '/panel/index.html'.

## 🎮 Servidores de Juegos
- **Mindustry:** v157.1 (oldshensheep/mindustry-server).
- **Path:** /home/opti/proyectos/mindustry/
- **Puertos:** 6567 TCP/UDP.

## 🛡️ Automatización Confirmada
- **GitHub:** 03:00 AM (Dom) - backup_github.sh.
- **Drive:** 04:00 AM (Dom) - backup_drive.sh.

## 🐳 Gestión
- **Cockpit:** Puerto 9090 (Sistema).
- **Portainer:** Puerto 9443 (Docker).
