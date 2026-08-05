# Activate the certbot virtualenv and run dashddi-certbot to renew certificates,
# updating the SAN list to match the current FQDNs registered in DashDDI.
#
# Note: Install-DashddiCertbot.ps1 writes a copy of this script to the
# virtualenv directory (C:\Certbot\ by default) with the correct paths
# embedded. This copy in the repo is for reference only.
& "C:\Certbot\Scripts\Activate.ps1"
dashddi-certbot --credentials "C:\Certbot\dashddi.ini"
