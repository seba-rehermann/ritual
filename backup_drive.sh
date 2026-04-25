#!/bin/bash
# Sincroniza la carpeta de archivos del Ritual con una carpeta nueva en Drive
# El comando 'sync' hace que lo que borres en la Opti se borre en el Drive (mantiene espejo)
# Si preferís que nunca borre nada en Drive, usá 'copy' en lugar de 'sync'

echo "Iniciando respaldo a Google Drive: $(date)" >> /home/opti/proyectos/ritual/backup.log

rclone sync /home/opti/proyectos/ritual/compartido google_drive:Respaldo_Ritual_Optiplex --progress

echo "Respaldo finalizado: $(date)" >> /home/opti/proyectos/ritual/backup.log
