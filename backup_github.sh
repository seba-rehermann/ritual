#!/bin/bash
cd /home/opti/proyectos/ritual
git add .
git commit -m "Respaldo automático: $(date +'%Y-%m-%d %H:%M:%S')"
git push origin main
