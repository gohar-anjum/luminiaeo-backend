from sklearn.metrics.pairwise import cosine_similarity
import numpy as np

def compute_similarity(vec1, vec2):
    return float(cosine_similarity(
        np.array(vec1).reshape(1,-1),
        np.array(vec2).reshape(1,-1)
    )[0][0])
