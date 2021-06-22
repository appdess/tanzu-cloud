#!/bin/bash
# quick and dirty build script for tito-app ;-)
# https://github.com/appdess/Tanzu-VMC-Demo

VERSION=v1
set -euo pipefail
cd /home/tkg/Downloads/tanzu-cloud/docker/frontend
docker build -t frontend:$VERSION .
docker tag frontend:$VERSION harbor.cemeavmc.lab/tito/tito-fe:$VERSION
docker push harbor.cemeavmc.lab/tito/tito-fe:$VERSION

# build sql container
cd /home/tkg/Downloads/tanzu-cloud/docker/sql
docker build -t sql:$VERSION .
docker tag sql:$VERSION harbor.cemeavmc.lab/tito/tito-sql:$VERSION
docker push harbor.cemeavmc.lab/tito/tito-sql:$VERSION