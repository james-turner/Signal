Run supervisor as another user for ownership reasons, and to stop running as root, or as the current user
Run supervisor from current working directory?
Run workers with naming convention for process title?
Run with a pid file?
Allow hooks via connection streams to monitor what's going on - upon connection can I adjust the monitoring level?

Application Server Adapter

MQ Adapter(s)/Bridge(s) - ZeroMQ, RabbitMQ, MessagePack?

Heartbeat - Run another process off the parent (pre-fork) in detached mode to make sure the parent is monitored.

Clustering - Heirarchy of supervisors, where the top level acts like a register point for 2nd tier.
             2nd tier register with their parent tier (use sockets or something!) the parent can then shift off
             requests to that tier. There needs to be some form of chat to make sure the registrant hasn't gone
             away.


                S-H                     <-- Supervisor + Hearbeat to make sure process stays up (kill those
   ______________|______________
  |      |       |      |       |
  W      W       W      W       W       <-- Workers accept connections from other supervisors (act like reverse proxy)
  |                                     <-- All children race to accept requests from an upstream worker, if 1 worker drops out all the children
  |_____________________________            are still able to request from the remaining workers
  |      |       |      |       |
  S-H    S-H     S-H    S-H     S-H     <-- Supervisor registers upstream with parent
  |      |       |      |       |
 ___    ___     ___    ___     ___
|   |  |   |   |   |  |   |   |   |
W   W  W   W   W   W  W   W   W   W     <-- Workers deal with actual requests