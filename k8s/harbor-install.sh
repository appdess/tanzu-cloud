


helm install harbor harbor/harbor --set expose.ingress.hosts.core=core.harbor.cemeavmc.lab --set externalURL=https://harbor.cemeavmc.lab
# PW: Harbor12345
# Move certificate from Harbor login
ca.crt to /etc/docker/certs.d/harbor.cemeavmc.lab/

docker login harbor.cemeavmc.lab
