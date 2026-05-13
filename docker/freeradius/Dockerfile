FROM freeradius/freeradius-server:3.2

# Remove all default enabled modules and sites for a clean slate
RUN rm -f /etc/freeradius/3.0/mods-enabled/* \
       /etc/freeradius/3.0/sites-enabled/*

COPY radiusd.conf           /etc/freeradius/3.0/radiusd.conf
COPY clients.conf           /etc/freeradius/3.0/clients.conf
COPY mods-available/sql     /etc/freeradius/3.0/mods-available/sql
COPY sites-available/default /etc/freeradius/3.0/sites-available/default

RUN ln -s ../mods-available/sql      /etc/freeradius/3.0/mods-enabled/sql \
 && ln -s ../sites-available/default /etc/freeradius/3.0/sites-enabled/default
