resource "aws_elasticache_cluster" "titocache-tf" {
  cluster_id           = "titocache-tf"
  engine               = "memcached"
  node_type            = "cache.t2.micro"
  num_cache_nodes      = 1
  availability_zone    = "eu-central-1c"
  parameter_group_name = "default.memcached1.6"
  port                 = 11211
  subnet_group_name    = "default"
}

output "cluster-address" {
  value = aws_elasticache_cluster.titocache-tf.cluster_address
}
